package com.smb.master

import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35])
class SmbAdminApiTest {
    private lateinit var server: MockWebServer
    private lateinit var api: SmbAdminApi

    @Before fun setUp() {
        server = MockWebServer().apply { start() }
        api = SmbAdminApi(server.url("/").toString())
    }

    @After fun tearDown() { server.shutdown() }

    @Test fun loginUsesAndroidChallengeAndEnrollmentSecret() {
        server.enqueue(json(202, """{"success":true,"message":"Perlu TOTP","data":{"challenge_token":"${"c".repeat(64)}","two_factor_enrollment":{"secret":"SECRET"}}}"""))
        val (challenge, message) = api.beginLogin("operator@example.test", "strong password")

        val request = server.takeRequest()
        assertEquals("/api/v1/auth/login", request.path)
        assertEquals("android", JSONObject(request.body.readUtf8()).getString("client_type"))
        assertEquals("SECRET", challenge.setupSecret)
        assertTrue(message.contains("TOTP"))
        assertEquals(null, request.getHeader("Authorization"))
    }

    @Test fun verifiesTotpAndReturnsScopedServerPermissions() {
        server.enqueue(json(200, """{"success":true,"data":{"access_token":"plain-token","expires_at":"2030-01-01T00:00:00Z","user":{"name":"Admin SMB","permissions":["devices.view","devices.location"]},"recovery_codes":["code-one"]}}"""))
        val session = api.verifyLogin(LoginChallenge("${"c".repeat(64)}", null), "123456", false)

        val request = server.takeRequest()
        val body = JSONObject(request.body.readUtf8())
        assertEquals("/api/v1/auth/login/verify", request.path)
        assertEquals("123456", body.getString("totp_code"))
        assertTrue(!body.has("recovery_code"))
        assertEquals("plain-token", session.token)
        assertTrue("devices.location" in session.permissions)
        assertEquals(listOf("code-one"), session.recoveryCodes)
    }

    @Test fun commandRequiresPermissionAndUsesServerCommandBroker() {
        server.enqueue(json(200, """{"success":true,"data":{"permissions":["devices.command","devices.location"]}}"""))
        server.enqueue(json(200, """{"success":true,"data":{"permissions":["devices.command","devices.location"]}}"""))
        server.enqueue(json(201, """{"success":true,"message":"Menunggu gateway","data":{"command_id":"cmd-1","status":"PENDING"}}"""))
        val device = ManagedDevice("device-1", "Unit 1", "ONLINE", "Site A", "Team A", 80, 35, null)

        val result = api.command("admin-token", device, "REQUEST_LOCATION")

        val permissionCheck = server.takeRequest()
        val commandPermissionCheck = server.takeRequest()
        val command = server.takeRequest()
        assertEquals("/api/v1/auth/me", permissionCheck.path)
        assertEquals("/api/v1/auth/me", commandPermissionCheck.path)
        assertEquals("Bearer admin-token", command.getHeader("Authorization"))
        assertEquals("/api/v1/devices/device-1/commands", command.path)
        assertTrue(command.getHeader("Idempotency-Key")!!.startsWith("master-"))
        assertEquals("REQUEST_LOCATION", JSONObject(command.body.readUtf8()).getString("command_type"))
        assertEquals("PENDING", result.status)
        assertEquals("cmd-1", result.id)
    }

    @Test fun deniedPermissionDoesNotSendDeviceCommand() {
        server.enqueue(json(200, """{"success":true,"data":{"permissions":["devices.view"]}}"""))
        val device = ManagedDevice("device-1", "Unit 1", "ONLINE", "Site A", "Team A", null, 35, null)

        val failure = runCatching { api.command("admin-token", device, "REQUEST_LOCATION") }.exceptionOrNull()
        assertTrue(failure is ApiFailure && failure.status == 403)
        assertEquals(1, server.requestCount)
    }

    @Test fun deviceInventoryUsesOnlyServerReturnedIdentityAndScope() {
        server.enqueue(json(200, """{"success":true,"data":{"items":[{"id":"server-uuid","name":"Field Unit","status":"DEGRADED","site":{"name":"Jakarta"},"team":{"name":"Survey"},"battery_percent":42,"android_api":35,"last_seen_at":"2026-10-07T01:00:00Z"}],"pagination":{"total":1,"current_page":1,"last_page":1,"per_page":50}}}"""))

        val device = api.devices("admin-token").single()

        assertEquals("server-uuid", device.id)
        assertEquals("Jakarta", device.site)
        assertEquals("Survey", device.team)
        assertEquals("DEGRADED", device.status)
        assertEquals(42, device.battery)
    }

    @Test fun dashboardStatusCountsComeFromServerPaginationTotals() {
        listOf(4, 2, 3, 1).forEach { total ->
            server.enqueue(json(200, """{"success":true,"data":{"items":[],"pagination":{"total":$total,"current_page":1,"last_page":$total,"per_page":1}}}"""))
        }

        val summary = api.deviceStatusSummary("admin-token")

        assertEquals(10, summary.total)
        assertEquals(4, summary.online)
        assertEquals(2, summary.degraded)
        assertEquals(3, summary.offline)
        assertEquals(1, summary.unknown)
        assertEquals("/api/v1/devices?status=ONLINE&per_page=1", server.takeRequest().path)
        assertEquals("/api/v1/devices?status=DEGRADED&per_page=1", server.takeRequest().path)
        assertEquals("/api/v1/devices?status=OFFLINE&per_page=1", server.takeRequest().path)
        assertEquals("/api/v1/devices?status=UNKNOWN&per_page=1", server.takeRequest().path)
    }

    @Test fun sessionListPreservesCurrentSessionFlagForSafeRevokeUi() {
        server.enqueue(json(200, """{"success":true,"data":[{"id":"token-12","name":"SMB Master Android","type":"android","is_current":true,"expires_at":"2030-01-01T00:00:00Z"},{"id":"token-14","name":"Other phone","type":"android","is_current":false}]}"""))

        val sessions = api.sessions("admin-token")

        assertEquals(2, sessions.size)
        assertTrue(sessions.first().isCurrent)
        assertTrue(!sessions.last().isCurrent)
        assertEquals("token-12", sessions.first().id)
    }

    @Test fun auditLogsRequirePermissionAndReadOnlyFromScopedLaravelEndpoint() {
        server.enqueue(json(200, """{"success":true,"data":{"permissions":["audit.view"]}}"""))
        server.enqueue(json(200, """{"success":true,"data":{"items":[{"id":9,"event":"device.locked","subject_type":"device","subject_id":"device-1","actor":{"id":2,"name":"Admin SMB"},"created_at":"2026-10-07T01:00:00Z","properties":{}}]}}"""))

        val records = api.auditLogs("admin-token")

        assertEquals(1, records.size)
        assertEquals("device.locked", records.single().title)
        assertTrue(records.single().subtitle.contains("Admin SMB · device · device-1"))
        assertEquals("/api/v1/auth/me", server.takeRequest().path)
        val auditRequest = server.takeRequest()
        assertEquals("/api/v1/audit-logs?page=1&per_page=25", auditRequest.path)
        assertEquals("Bearer admin-token", auditRequest.getHeader("Authorization"))
    }

    @Test fun siteAndTeamMutationsUseAuthorizedLaravelResources() {
        repeat(4) { server.enqueue(json(200, """{"success":true,"message":"OK","data":{}}""")) }

        api.createSite("admin-token", "Jakarta", "JKT-01")
        api.createTeam("admin-token", "17", "Survey")
        api.deleteSite("admin-token", "17")
        api.deleteTeam("admin-token", "29")

        val createSite = server.takeRequest()
        val createTeam = server.takeRequest()
        val deleteSite = server.takeRequest()
        val deleteTeam = server.takeRequest()
        assertEquals("POST", createSite.method)
        assertEquals("/api/v1/sites", createSite.path)
        assertEquals("JKT-01", JSONObject(createSite.body.readUtf8()).getString("code"))
        assertEquals("/api/v1/teams", createTeam.path)
        assertEquals(17, JSONObject(createTeam.body.readUtf8()).getInt("site_id"))
        assertEquals("DELETE", deleteSite.method)
        assertEquals("/api/v1/sites/17", deleteSite.path)
        assertEquals("/api/v1/teams/29", deleteTeam.path)
    }

    private fun json(status: Int, body: String) = MockResponse().setResponseCode(status)
        .setHeader("Content-Type", "application/json").setBody(body)
}
