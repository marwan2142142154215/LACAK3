package com.smb.master

import android.os.Bundle
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.location.LocationManager
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.Instant
import java.util.Locale

class MainActivity : AppCompatActivity() {
    private val api = SmbAdminApi()
    private lateinit var vault: SecureTokenStore
    private lateinit var content: LinearLayout
    private var challenge: LoginChallenge? = null
    private var devices = emptyList<ManagedDevice>()
    private var deviceSummary = DeviceStatusSummary(0, 0, 0, 0)
    private var profileName = "Operator SMB"
    private var permissions = emptySet<String>()
    private var token = ""
    private var bleScanner: SmbBleScanner? = null
    private var bleScanStatus = ""
    private var nearbyBleDevices = emptyList<NearbyBleDevice>()
    private val blePermissionRequest = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants ->
        if (grants.values.all { it }) startBleScan()
        else showDashboard("Izin BLE belum diberikan. Pemindaian memerlukan izin Bluetooth, atau lokasi pada Android 8–11.")
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)
        window.statusBarColor = Color.TRANSPARENT
        window.navigationBarColor = Color.TRANSPARENT
        vault = SecureTokenStore(getSharedPreferences("smb_master_secure", MODE_PRIVATE))
        token = vault.read()?.takeIf { !isExpired(it.second) }?.first.orEmpty()
        if (token.isBlank()) showLogin() else {
            showDashboardLoading()
            refreshDashboard()
        }
    }

    override fun onStop() {
        bleScanner?.cancel()
        bleScanner = null
        super.onStop()
    }

    private fun base(title: String, subtitle: String): LinearLayout {
        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20), dp(22), dp(20), dp(20)); setBackgroundColor(CANVAS) }
        val originalPadding = intArrayOf(root.paddingLeft, root.paddingTop, root.paddingRight, root.paddingBottom)
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            view.setPadding(originalPadding[0] + bars.left, originalPadding[1] + bars.top,
                originalPadding[2] + bars.right, originalPadding[3] + bars.bottom)
            insets
        }
        val hero = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(21), dp(20), dp(21), dp(22))
            background = GradientDrawable(GradientDrawable.Orientation.TL_BR, intArrayOf(NAVY, 0xFF173E60.toInt(), 0xFF176B75.toInt())).apply { cornerRadius = dp(24).toFloat() }
            elevation = dp(3).toFloat()
        }
        val brand = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = android.view.Gravity.CENTER_VERTICAL }
        brand.addView(TextView(this).apply {
            text = "S"
            textSize = 17f
            setTypeface(typeface, android.graphics.Typeface.BOLD)
            setTextColor(Color.WHITE)
            gravity = android.view.Gravity.CENTER
            background = GradientDrawable().apply { shape = GradientDrawable.RECTANGLE; cornerRadius = dp(12).toFloat(); setColor(0x2AFFFFFF) }
        }, LinearLayout.LayoutParams(dp(36), dp(36)))
        brand.addView(TextView(this).apply {
            text = "SMB  /  DEVICE OPERATIONS"
            textSize = 11f
            letterSpacing = 0.08f
            setTypeface(typeface, android.graphics.Typeface.BOLD)
            setTextColor(0xFFB9F3E8.toInt())
            setPadding(dp(10), 0, 0, 0)
        })
        hero.addView(brand)
        hero.addView(TextView(this).apply {
            text = title
            textSize = 27f
            setTextColor(Color.WHITE)
            setTypeface(typeface, android.graphics.Typeface.BOLD)
            setPadding(0, dp(17), 0, dp(3))
        })
        hero.addView(TextView(this).apply {
            text = subtitle
            textSize = 14f
            setTextColor(0xFFE1EAF2.toInt())
            setLineSpacing(dp(2).toFloat(), 1f)
        })
        root.addView(hero, LinearLayout.LayoutParams(-1, -2).apply { bottomMargin = dp(18) })
        val scroll = ScrollView(this)
        content = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        scroll.addView(content)
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f))
        setContentView(root)
        WindowInsetsControllerCompat(window, root).apply {
            isAppearanceLightStatusBars = true
            isAppearanceLightNavigationBars = true
        }
        ViewCompat.requestApplyInsets(root)
        return root
    }

    private fun showLogin(error: String? = null) {
        base("SMB Master", "Masuk dengan akun operator yang terdaftar.")
        if (error != null) addText(error, 0xFFB42318.toInt())
        val username = addField("User ID", android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS)
        val password = addField("Password", android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD)
        addButton("Masuk") {
            val usernameText = username.text.toString().trim()
            val passText = password.text.toString()
            if (usernameText.isBlank() || passText.isBlank()) { showLogin("User ID dan password wajib diisi."); return@addButton }
            runTask({ api.beginLogin(usernameText, passText) }) { result ->
                challenge = result.first
                showSecondFactor(result.first, result.second)
            }
        }
        addText("Semua permintaan admin melewati HTTPS API SMB. Aplikasi tidak terhubung langsung ke perangkat.", 0xFF5B6B61.toInt())
    }

    private fun showSecondFactor(active: LoginChallenge, message: String) {
        base("Verifikasi 2FA", message)
        active.setupSecret?.let { secret -> addText("Tambahkan secret ini ke aplikasi authenticator lalu masukkan kode TOTP untuk mengaktifkan 2FA:\n\n$secret", 0xFF14532D.toInt()) }
        val code = addField(if (active.setupSecret == null) "Kode TOTP" else "Kode TOTP authenticator", android.text.InputType.TYPE_CLASS_NUMBER)
        val recovery = addField("Recovery code (opsional)", android.text.InputType.TYPE_CLASS_TEXT)
        addButton("Verifikasi") {
            val useRecovery = recovery.text.toString().isNotBlank()
            val factor = if (useRecovery) recovery.text.toString() else code.text.toString()
            if (factor.isBlank()) { showSecondFactor(active, "Masukkan kode TOTP atau recovery code."); return@addButton }
            runTask({ api.verifyLogin(active, factor, useRecovery) }) { session ->
                token = session.token
                profileName = session.userName
                permissions = session.permissions
                vault.write(session.token, session.expiresAt)
                if (session.recoveryCodes.isNotEmpty()) showRecoveryCodes(session.recoveryCodes)
                refreshDashboard()
            }
        }
        addButton("Kembali ke login") { challenge = null; showLogin() }
    }

    private fun showRecoveryCodes(codes: List<String>) {
        AlertDialog.Builder(this).setTitle("Simpan recovery codes")
            .setMessage(codes.joinToString("\n") + "\n\nKode hanya ditampilkan satu kali. Simpan di tempat aman.")
            .setPositiveButton("Saya sudah menyimpan", null).show()
    }

    private fun refreshDashboard() = runTask({
        val user = api.me(token)
        val page = api.devices(token)
        val summary = api.deviceStatusSummary(token)
        Triple(user, page, summary)
    }) { (user, result, summary) ->
        profileName = user.optString("name", profileName)
        permissions = user.optJSONArray("permissions").let { array -> (0 until (array?.length() ?: 0)).mapNotNull { array?.optString(it) }.toSet() }
        devices = result
        deviceSummary = summary
        showDashboard()
    }

    private fun showDashboard(error: String? = null) {
        base("Dashboard", "Selamat datang, $profileName. Ringkasan armada dari data server.")
        if (error != null) addText(error, 0xFFB42318.toInt())
        addSectionHeader("Ringkasan armada", "Hitungan berdasarkan status heartbeat server")
        addDeviceMetricGrid()
        addSectionHeader("Akses cepat", "Operasi yang tersedia untuk akun Anda")
        if ("devices.view" in permissions) addButton("Cari SMB Lacak terdekat via BLE", accent = true) { requestBleScan() }
        val quickActions = mutableListOf<Pair<String, () -> Unit>>()
        quickActions += "Muat ulang daftar" to { refreshDashboard() }
        if ("sites.view" in permissions) quickActions += "Kelola site dan tim" to { showSitesTeams() }
        quickActions += "Sesi login" to { showSessions() }
        if ("audit.view" in permissions) quickActions += "Audit aktivitas" to { showAuditLogs() }
        addQuickActionGrid(quickActions)
        if (bleScanStatus.isNotBlank()) addText(bleScanStatus, 0xFF344054.toInt())
        nearbyBleDevices.forEach { nearby ->
            val card = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(dp(17), dp(16), dp(17), dp(16))
                applySurface(this)
            }
            card.addView(TextView(this).apply {
                text = nearby.name
                textSize = 16f
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                setTextColor(0xFF10261B.toInt())
            })
            card.addView(TextView(this).apply {
                text = "${nearby.site} / ${nearby.team}\n${nearby.proximity} · RSSI ${nearby.rssi} dBm · ${nearby.observedAt}"
                textSize = 13f
                setTextColor(0xFF344054.toInt())
                setPadding(0, dp(5), 0, dp(8))
            })
            devices.firstOrNull { it.id == nearby.id }?.let { device ->
                card.addView(smallButton("Buka detail perangkat") { showDeviceDetail(device) })
            }
            content.addView(card, LinearLayout.LayoutParams(-1, -2).apply { bottomMargin = dp(10) })
        }
        addSectionHeader("Perangkat terbaru", "${deviceSummary.total} perangkat dalam cakupan akses")
        if (devices.isEmpty()) addText("Belum ada perangkat yang dapat Anda lihat.", 0xFF5B6B61.toInt())
        devices.forEach { device ->
            val card = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(17), dp(17), dp(17), dp(17)); applySurface(this) }
            card.addView(TextView(this).apply { text = device.name; textSize = 18f; setTypeface(typeface, android.graphics.Typeface.BOLD); setTextColor(0xFF10261B.toInt()) })
            val statusColor = when (device.status.uppercase(Locale.ROOT)) { "ONLINE" -> 0xFF15803D.toInt(); "DEGRADED" -> 0xFFB54708.toInt(); "OFFLINE" -> 0xFFB42318.toInt(); else -> 0xFF667085.toInt() }
            card.addView(TextView(this).apply {
                text = "${device.status} · ${device.site.ifBlank { "Tanpa lokasi" }} / ${device.team.ifBlank { "Tanpa tim" }}\nAndroid API ${device.androidApi} · Baterai ${device.battery?.let { "$it%" } ?: "—"}\nTerakhir terlihat: ${device.lastSeen ?: "belum ada heartbeat"}"
                textSize = 13f; setTextColor(statusColor); setPadding(0, dp(6), 0, dp(8))
            })
            val actions = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            if ("devices.command" in permissions && "devices.location" in permissions) actions.addView(smallButton("Minta lokasi") { sendCommand(device, "REQUEST_LOCATION") })
            if ("devices.command" in permissions && "devices.lock" in permissions) actions.addView(smallButton("Kunci") { confirmLock(device) })
            if ("devices.unlock" in permissions) actions.addView(smallButton("Buka kunci") {
                AlertDialog.Builder(this@MainActivity).setMessage("SMB Lacak belum menyediakan jalur unlock remote yang valid untuk semua mode Android. Tidak ada command palsu yang dikirim.")
                    .setPositiveButton("Mengerti", null).show()
            })
            card.addView(actions, LinearLayout.LayoutParams(-1, -2).apply { topMargin = dp(5) })
            card.addView(smallButton("Detail dan riwayat command") { showDeviceDetail(device) })
            val wrapper = LinearLayout(this).apply { setPadding(0, 0, 0, dp(12)); addView(card, LinearLayout.LayoutParams(-1, -2)) }
            content.addView(wrapper)
        }
        addButton("Keluar dari sesi ini", primary = false) { logout() }
    }

    private fun showDashboardLoading() {
        base("Dashboard", "Memuat data akun dan armada dari server SMB.")
        val card = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = android.view.Gravity.CENTER_VERTICAL
            setPadding(dp(18), dp(18), dp(18), dp(18))
            applySurface(this)
            addView(android.widget.ProgressBar(this@MainActivity), LinearLayout.LayoutParams(dp(24), dp(24)))
            addView(TextView(this@MainActivity).apply {
                text = "Mengambil status terbaru dari serverâ€¦"
                textSize = 14f
                setTextColor(INK)
                setPadding(dp(12), 0, 0, 0)
            })
        }
        content.addView(card, LinearLayout.LayoutParams(-1, -2))
    }

    private fun addSectionHeader(title: String, subtitle: String) {
        content.addView(LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(2), dp(6), dp(2), dp(10))
            addView(TextView(this@MainActivity).apply {
                text = title
                textSize = 17f
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                setTextColor(INK)
            })
            if (subtitle.isNotBlank()) addView(TextView(this@MainActivity).apply {
                text = subtitle
                textSize = 12f
                setTextColor(0xFF52647A.toInt())
                setPadding(0, dp(3), 0, 0)
            })
        })
    }

    private fun addDeviceMetricGrid() {
        val metrics = listOf(
            Triple("Total perangkat", deviceSummary.total, 0xFF1E5A8A.toInt()),
            Triple("Online", deviceSummary.online, 0xFF137A69.toInt()),
            Triple("Offline", deviceSummary.offline, 0xFF66758A.toInt()),
            Triple("Degraded / Unknown", deviceSummary.degraded + deviceSummary.unknown, 0xFF9A6400.toInt()),
        )
        val grid = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        metrics.chunked(2).forEach { pair ->
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            pair.forEachIndexed { index, metric ->
                val card = LinearLayout(this).apply {
                    orientation = LinearLayout.VERTICAL
                    setPadding(dp(15), dp(14), dp(15), dp(14))
                    applySurface(this)
                    addView(TextView(this@MainActivity).apply {
                        text = metric.first
                        textSize = 12f
                        setTypeface(typeface, android.graphics.Typeface.BOLD)
                        setTextColor(0xFF52647A.toInt())
                    })
                    addView(TextView(this@MainActivity).apply {
                        text = metric.second.toString()
                        textSize = 27f
                        setTypeface(typeface, android.graphics.Typeface.BOLD)
                        setTextColor(metric.third)
                        setPadding(0, dp(4), 0, 0)
                    })
                    addView(TextView(this@MainActivity).apply {
                        text = "Dari server SMB"
                        textSize = 10f
                        setTextColor(0xFF66758A.toInt())
                    })
                }
                row.addView(card, LinearLayout.LayoutParams(0, -1, 1f).apply {
                    marginEnd = if (index == 0) dp(9) else 0
                    bottomMargin = dp(9)
                })
            }
            grid.addView(row, LinearLayout.LayoutParams(-1, -2))
        }
        content.addView(grid)
    }

    private fun addQuickActionGrid(actions: List<Pair<String, () -> Unit>>) {
        actions.chunked(2).forEach { pair ->
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            pair.forEachIndexed { index, action ->
                row.addView(smallButton(action.first, primary = false, action = action.second), LinearLayout.LayoutParams(0, dp(50), 1f).apply {
                    marginEnd = if (index == 0) dp(9) else 0
                    bottomMargin = dp(9)
                })
            }
            if (pair.size == 1) row.addView(View(this), LinearLayout.LayoutParams(0, dp(50), 1f).apply { marginStart = dp(9) })
            content.addView(row)
        }
    }

    private fun showDeviceDetail(device: ManagedDevice) {
        runTask({
            api.deviceDetail(token, device) to api.commands(token, device)
        }) { (detail, history) ->
            base(device.name, "Detail server dan riwayat command")
            val location = detail.optJSONObject("latest_location")
            val capabilities = detail.optJSONObject("capabilities")
            val locationSummary = location?.let { "${it.optDouble("latitude")}, ${it.optDouble("longitude")} · ±${it.optDouble("accuracy_meters")}m · ${it.optString("recorded_at")}" } ?: "belum ada lokasi"
            addText("ID: ${detail.optString("id")}\nNama: ${detail.optString("name")}\nSite: ${detail.optJSONObject("site")?.optString("name")}\nTim: ${detail.optJSONObject("team")?.optString("name")}\nStatus: ${detail.optString("status")}\nAndroid: ${detail.optString("android_version")} (API ${detail.optInt("android_api")})\nApp: ${detail.optString("app_version")}\nBattery: ${detail.opt("battery_percent")}\nHeartbeat: ${detail.optString("last_seen_at")}\nLokasi terbaru: $locationSummary\nCapability kamera: ${capabilities?.optBoolean("camera_available") ?: false} · terkelola: ${capabilities?.optBoolean("managed_device") ?: false}", 0xFF344054.toInt())
            addText("Riwayat command", 0xFF10261B.toInt())
            if (history.isEmpty()) addText("Belum ada command tercatat.", 0xFF667085.toInt())
            history.forEach { item -> addText("${item.title}\n${item.subtitle}", 0xFF344054.toInt()) }
            addButton("Kembali") { showDashboard() }
        }
    }

    private fun showSitesTeams(error: String? = null) {
        runTask({ api.sites(token) to api.teams(token) }) { (sites, teams) ->
            base("Site dan tim", "Data yang diberikan oleh API sesuai hak akses akun ini.")
            if (error != null) addText(error, 0xFFB42318.toInt())
            addText("Sites", 0xFF10261B.toInt())
            sites.forEach { site ->
                addText("${site.title} · ${site.subtitle}", 0xFF344054.toInt())
                if ("sites.manage" in permissions) addButton("Hapus site ${site.title}") { confirmDelete("site", site.title) { api.deleteSite(token, site.id) } }
            }
            addText("Teams", 0xFF10261B.toInt())
            teams.forEach { team ->
                addText("${team.title} · site ${team.subtitle}", 0xFF344054.toInt())
                if ("teams.manage" in permissions) addButton("Hapus tim ${team.title}") { confirmDelete("tim", team.title) { api.deleteTeam(token, team.id) } }
            }
            if ("sites.manage" in permissions) addButton("Tambah site") { createSiteDialog() }
            if ("teams.manage" in permissions) addButton("Tambah tim") { chooseSiteForTeam(sites) }
            addButton("Kembali") { showDashboard() }
        }
    }

    private fun createSiteDialog() {
        val form = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20), dp(8), dp(20), 0) }
        val name = EditText(this).apply { hint = "Nama site"; setSingleLine(true) }
        val code = EditText(this).apply { hint = "Kode site (huruf/angka/-/_)"; setSingleLine(true) }
        form.addView(name); form.addView(code)
        AlertDialog.Builder(this).setTitle("Tambah site").setView(form)
            .setNegativeButton("Batal", null)
            .setPositiveButton("Simpan") { _, _ -> runTask({ api.createSite(token, name.text.toString().trim(), code.text.toString().trim()) }) { showSitesTeams() } }
            .show()
    }

    private fun chooseSiteForTeam(sites: List<AdminRecord>) {
        if (sites.isEmpty()) { showSitesTeams("Buat site terlebih dahulu sebelum menambah tim."); return }
        AlertDialog.Builder(this).setTitle("Pilih site")
            .setItems(sites.map { "${it.title} · ${it.subtitle}" }.toTypedArray()) { _, index -> createTeamDialog(sites[index]) }.show()
    }

    private fun createTeamDialog(site: AdminRecord) {
        val name = EditText(this).apply { hint = "Nama tim"; setSingleLine(true); setPadding(dp(20), dp(12), dp(20), dp(8)) }
        AlertDialog.Builder(this).setTitle("Tim baru · ${site.title}").setView(name)
            .setNegativeButton("Batal", null)
            .setPositiveButton("Simpan") { _, _ -> runTask({ api.createTeam(token, site.id, name.text.toString().trim()) }) { showSitesTeams() } }
            .show()
    }

    private fun confirmDelete(kind: String, label: String, delete: suspend () -> Unit) {
        AlertDialog.Builder(this).setTitle("Hapus $kind?").setMessage("Hapus $label? Server akan menolak jika masih memiliki data terkait.")
            .setNegativeButton("Batal", null)
            .setPositiveButton("Hapus") { _, _ -> runTask({ delete() }) { showSitesTeams() } }.show()
    }

    private fun showSessions() {
        runTask({ api.sessions(token) }) { items ->
            base("Sesi login", "Sesi diatur oleh server dan dapat dicabut.")
            if (items.isEmpty()) addText("Tidak ada sesi aktif yang dikembalikan server.", 0xFF667085.toInt())
            items.forEach { session ->
                addText("${session.name} · ${session.type}${if (session.isCurrent) " · sesi ini" else ""}\n${session.expiresAt ?: "Masa berlaku mengikuti kebijakan server"}", 0xFF344054.toInt())
                if (!session.isCurrent) addButton("Cabut ${session.name}") {
                    AlertDialog.Builder(this).setMessage("Cabut sesi ${session.name}?")
                        .setNegativeButton("Batal", null)
                        .setPositiveButton("Cabut") { _, _ -> runTask({ api.revokeSession(token, session.id) }) { showSessions() } }.show()
                }
            }
            addButton("Kembali") { showDashboard() }
        }
    }

    private fun showAuditLogs(page: Int = 1) {
        runTask({ api.auditLogs(token, page) }) { rows ->
            base("Audit aktivitas", "Catatan berasal dari Laravel dan dibatasi sesuai izin audit akun ini.")
            if (rows.isEmpty()) addText("Tidak ada audit log pada halaman ini.", 0xFF667085.toInt())
            rows.forEach { row ->
                addText(row.title, 0xFF10261B.toInt())
                addText(row.subtitle, 0xFF667085.toInt())
            }
            if (page > 1) addButton("Halaman sebelumnya") { showAuditLogs(page - 1) }
            if (rows.size == 25) addButton("Halaman berikutnya") { showAuditLogs(page + 1) }
            addButton("Kembali") { showDashboard() }
        }
    }

    private fun confirmLock(device: ManagedDevice) {
        AlertDialog.Builder(this).setTitle("Kunci ${device.name}?")
            .setMessage("Perintah hanya dapat berhasil jika SMB Lacak telah diprovisikan sebagai Android Device Owner dan perangkat terhubung.")
            .setNegativeButton("Batal", null)
            .setPositiveButton("Kirim permintaan") { _, _ -> sendCommand(device, "LOCK") }.show()
    }

    private fun sendCommand(device: ManagedDevice, type: String) {
        runTask({ api.command(token, device, type) }) { command ->
            AlertDialog.Builder(this).setTitle("Command ${command.status}")
                .setMessage("ID: ${command.id}\n${command.message}\n\nStatus akan berubah setelah gateway dan perangkat memberikan acknowledgement.")
                .setPositiveButton("Tutup", null).show()
        }
    }

    private fun logout() {
        runTask({ api.logout(token) }) {
            vault.clear(); token = ""; devices = emptyList(); showLogin("Sesi sudah ditutup.")
        }
    }

    private fun requestBleScan() {
        if ("devices.view" !in permissions) {
            showDashboard("Akun ini tidak memiliki izin untuk melihat perangkat.")
            return
        }
        val required = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            arrayOf(Manifest.permission.BLUETOOTH_SCAN)
        } else {
            arrayOf(Manifest.permission.ACCESS_FINE_LOCATION)
        }
        val missing = required.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isNotEmpty()) {
            blePermissionRequest.launch(missing.toTypedArray())
            return
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S && !isLocationEnabled()) {
            showDashboard("Aktifkan layanan Lokasi Android untuk pemindaian BLE pada Android 8–11.")
            return
        }
        startBleScan()
    }

    private fun startBleScan() {
        bleScanner?.cancel()
        val scanner = SmbBleScanner(this)
        bleScanner = scanner
        bleScanStatus = "Memindai BLE selama 10 detik… Perangkat harus menyiarkan identitas SMB yang masih berlaku."
        nearbyBleDevices = emptyList()
        showDashboard()
        val started = scanner.start { observations, scanError ->
            runOnUiThread {
                if (scanError != null) {
                    bleScanStatus = scanError
                    showDashboard()
                } else if (observations.isEmpty()) {
                    bleScanStatus = "Tidak ada iklan BLE SMB yang ditemukan dalam jendela pemindaian ini."
                    showDashboard()
                } else {
                    runTask({ api.reportBleObservations(token, observations) }) { matched ->
                        nearbyBleDevices = matched
                        bleScanStatus = if (matched.isEmpty()) {
                            "Iklan BLE ditemukan, tetapi tidak cocok dengan identitas perangkat aktif yang boleh dilihat oleh akun ini."
                        } else {
                            "Server memvalidasi ${matched.size} perangkat. RSSI hanya perkiraan kedekatan, bukan jarak meter."
                        }
                        showDashboard()
                    }
                }
            }
        }
        if (!started) {
            bleScanStatus = "Pemindaian BLE tidak dapat dimulai. Periksa Bluetooth, izin, dan dukungan BLE perangkat."
            showDashboard()
        }
    }

    private fun isLocationEnabled(): Boolean = runCatching {
        val manager = getSystemService(LOCATION_SERVICE) as LocationManager
        manager.isProviderEnabled(LocationManager.GPS_PROVIDER) || manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
    }.getOrDefault(false)

    private fun addField(label: String, inputType: Int): EditText {
        val field = EditText(this).apply {
            hint = label; this.inputType = inputType; setSingleLine(true)
            textSize = 15f
            setTextColor(INK); setHintTextColor(0xFF66758A.toInt())
            setPadding(dp(15), dp(12), dp(15), dp(12))
            background = GradientDrawable().apply { cornerRadius = dp(14).toFloat(); setColor(Color.WHITE); setStroke(dp(1), 0xFFD7E0EB.toInt()) }
        }
        content.addView(field, LinearLayout.LayoutParams(-1, dp(52)).apply { bottomMargin = dp(12) })
        return field
    }

    private fun addButton(label: String, primary: Boolean = true, accent: Boolean = false, action: () -> Unit) {
        content.addView(smallButton(label, primary, accent, action), LinearLayout.LayoutParams(-1, dp(54)).apply { bottomMargin = dp(11) })
    }

    private fun smallButton(label: String, primary: Boolean = true, accent: Boolean = false, action: () -> Unit): Button = MaterialButton(this).apply {
        text = label; isAllCaps = false; textSize = 14f; letterSpacing = 0.01f
        setTypeface(typeface, android.graphics.Typeface.BOLD)
        setTextColor(if (primary || accent) Color.WHITE else NAVY)
        backgroundTintList = ColorStateList.valueOf(when { accent -> TEAL; primary -> NAVY; else -> Color.WHITE })
        strokeWidth = if (primary || accent) 0 else dp(1)
        strokeColor = ColorStateList.valueOf(0xFFD7E0EB.toInt())
        rippleColor = ColorStateList.valueOf(0x3333C7B7)
        cornerRadius = dp(15)
        insetTop = 0
        insetBottom = 0
        elevation = if (primary || accent) dp(2).toFloat() else 0f
        setOnClickListener { action() }
        layoutParams = LinearLayout.LayoutParams(0, dp(48), 1f).apply { marginEnd = dp(5) }
    }

    private fun addText(value: String, color: Int) {
        content.addView(TextView(this).apply {
            text = value
            textSize = 14f
            setTextColor(color)
            setLineSpacing(dp(3).toFloat(), 1f)
            setPadding(dp(2), dp(8), dp(2), dp(12))
        })
    }

    private fun applySurface(view: View) {
        view.background = GradientDrawable().apply {
            cornerRadius = dp(20).toFloat()
            setColor(Color.WHITE)
            setStroke(dp(1), 0xFFE0E8F1.toInt())
        }
        view.elevation = dp(2).toFloat()
    }

    private fun <T> runTask(task: suspend () -> T, success: (T) -> Unit) {
        lifecycleScope.launch {
            try { success(withContext(Dispatchers.IO) { task() }) }
            catch (failure: Exception) {
                val message = (failure as? ApiFailure)?.message ?: failure.message ?: "Koneksi ke SMB API gagal."
                if ((failure as? ApiFailure)?.status == 401) { vault.clear(); token = ""; showLogin("Sesi berakhir. Masuk kembali untuk melanjutkan.") }
                else if (token.isBlank()) showLogin(message) else showDashboard(message)
            }
        }
    }

    private fun isExpired(value: String): Boolean = runCatching { Instant.parse(value).isBefore(Instant.now()) }.getOrDefault(true)
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    private companion object {
        const val NAVY = 0xFF102541.toInt()
        const val TEAL = 0xFF0F766E.toInt()
        const val CANVAS = 0xFFF3F6FB.toInt()
        const val INK = 0xFF12243A.toInt()
    }
}
