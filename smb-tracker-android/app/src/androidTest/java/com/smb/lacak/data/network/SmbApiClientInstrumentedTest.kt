package com.smb.lacak.data.network

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.device.DeviceCapabilities
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class SmbApiClientInstrumentedTest {
    private lateinit var server: MockWebServer
    private lateinit var client: SmbApiClient

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
        client = SmbApiClient(server.url("/").toString())
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun registrationSendsCapabilitiesAndReadsServerIssuedIdentity() {
        server.enqueue(
            MockResponse().setHeader("Content-Type", "application/json")
                .setBody("""{"success":true,"message":"registered","data":{"device_id":"server-uuid","device_credential":"${"b".repeat(64)}","credential_epoch":1}}"""),
        )

        val result = client.register(registration())
        val request = server.takeRequest()
        val body = JSONObject(request.body.readUtf8())

        assertEquals("server-uuid", result.deviceId)
        assertEquals("POST", request.method)
        assertEquals("/api/v1/device-registration", request.path)
        assertFalse(request.getHeader("Authorization") != null)
        assertEquals("R9-6X2", body.getString("registration_code"))
        assertEquals(true, body.getJSONObject("capabilities").getBoolean("front_camera_available"))
    }

    @Test
    fun heartbeatUsesCredentialBoundBearerAndServerAcknowledgement() {
        server.enqueue(
            MockResponse().setHeader("Content-Type", "application/json")
                .setBody("""{"success":true,"message":"accepted","data":{"accepted":true,"status":"ONLINE","server_received_at":"2026-10-07T00:00:00Z"}}"""),
        )
        val credential = StoredDeviceCredential("device-uuid", "c".repeat(64), 1)

        val result = client.sendHttpsHeartbeat(credential, JSONObject().put("request_id", "request-uuid"))
        val request = server.takeRequest()

        assertEquals("ONLINE", result.status)
        assertTrue(result.accepted)
        assertEquals("Bearer ${credential.credential}", request.getHeader("Authorization"))
        assertEquals("/api/v1/device/heartbeat", request.path)
    }

    @Test
    fun serverValidationFailureIsReturnedAsTypedErrorWithoutSuccessClaim() {
        server.enqueue(
            MockResponse().setResponseCode(422).setHeader("Content-Type", "application/json")
                .setBody("""{"success":false,"message":"Kode registrasi tidak valid.","errors":{"registration_code":["invalid"]}}"""),
        )

        val error = runCatching { client.register(registration()) }.exceptionOrNull()

        assertTrue(error is SmbApiException)
        assertEquals(422, (error as SmbApiException).statusCode)
        assertEquals("Kode registrasi tidak valid.", error.message)
    }

    private fun registration() = DeviceRegistration(
        registrationCode = " R9-6X2 ",
        name = "Android test device",
        manufacturer = "Test",
        model = "Emulator",
        androidVersion = "15",
        androidApi = 35,
        appVersion = "1.0.0",
        appVersionCode = 1,
        batteryPercent = 75,
        networkType = "WIFI",
        capabilities = DeviceCapabilities(
            cameraAvailable = true,
            frontCameraAvailable = true,
            backCameraAvailable = false,
            locationAvailable = false,
            managedDevice = false,
            deviceOwner = false,
            foregroundServiceAvailable = true,
            notificationPermission = true,
            bluetoothAvailable = false,
            bleSupported = false,
            bleAdvertisingSupported = false,
            bleScanningSupported = false,
        ),
    )
}
