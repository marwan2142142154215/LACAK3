package com.smb.lacak.agent

import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import android.content.Context
import com.smb.lacak.data.security.DeviceCredentialStore
import com.smb.lacak.data.security.StoredDeviceCredential
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class DeviceProtocolInstrumentedTest {
    private val credential = StoredDeviceCredential("device-uuid", "a".repeat(64), 2)

    @Test
    fun authenticationFrameBindsCredentialToServerAssignedDeviceId() {
        val frame = DeviceProtocol.authentication(credential)

        assertEquals("device.authenticate", frame.getString("type"))
        assertEquals("device-uuid", frame.getJSONObject("data").getString("device_id"))
        assertEquals("a".repeat(64), frame.getJSONObject("data").getString("device_credential"))
    }

    @Test
    fun heartbeatPayloadUsesProtocolEnvelope() {
        val payload = org.json.JSONObject().put("request_id", "request-1")
        val frame = DeviceProtocol.heartbeat(payload)

        assertEquals("device.heartbeat", frame.getString("type"))
        assertEquals("request-1", frame.getJSONObject("data").getString("request_id"))
    }

    @Test
    fun commandEnvelopeRequiresTheAuthenticatedDeviceAndSessionAndAcknowledgesTruthfully() {
        val deviceId = "33333333-3333-4333-8333-333333333333"
        val sessionId = "44444444-4444-4444-8444-444444444444"
        val commandId = "55555555-5555-4555-8555-555555555555"
        val event = org.json.JSONObject()
            .put("type", "device.command.created")
            .put(
                "data",
                org.json.JSONObject()
                    .put("command_id", commandId)
                    .put("device_id", deviceId)
                    .put("device_session_id", sessionId)
                    .put("command_type", "LOCK")
                    .put("sequence", 1)
                    .put("payload", org.json.JSONObject())
                    .put("expires_at", java.time.Instant.now().plusSeconds(60).toString()),
            )

        val parsed = DeviceProtocol.command(event, deviceId, sessionId)
        val acknowledgement = DeviceProtocol.commandAcknowledgement(commandId, "FAILED", "MANAGED_DEVICE_REQUIRED")

        assertEquals(commandId, parsed.commandId)
        assertEquals(1L, parsed.sequence)
        assertEquals("device.command.acknowledgement", acknowledgement.getString("type"))
        assertEquals("FAILED", acknowledgement.getJSONObject("data").getString("status"))
        assertFalse(acknowledgement.getJSONObject("data").has("result_message"))
        assertTrue(runCatching { DeviceProtocol.command(event, deviceId, "66666666-6666-4666-8666-666666666666") }.isFailure)
    }


    @Test
    fun commandReceiptIsDurableIdempotentAndRejectsAnOlderSequence() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("smb_device_command_receipts.db")
        val store = DeviceCommandReceiptStore(context)
        val deviceId = "33333333-3333-4333-8333-333333333333"
        val command = DeviceCommand(
            commandId = "55555555-5555-4555-8555-555555555555",
            deviceId = deviceId,
            deviceSessionId = "44444444-4444-4444-8444-444444444444",
            commandType = "LOCK",
            sequence = 7,
            payload = org.json.JSONObject(),
            expiresAtEpochMillis = System.currentTimeMillis() + 60_000,
            fingerprint = "a".repeat(64),
        )

        assertTrue(store.recordReceived(command) is RecordCommandResult.Stored)
        assertTrue(store.recordReceived(command) is RecordCommandResult.Duplicate)
        assertEquals("SUCCESS", store.updateStatus(command.commandId, "EXECUTING")
            ?.let { store.updateStatus(command.commandId, "SUCCESS")?.status })
        assertEquals("SUCCESS", DeviceCommandReceiptStore(context).find(command.commandId)?.status)
        val stale = command.copy(commandId = "77777777-7777-4777-8777-777777777777", sequence = 6)
        assertTrue(store.recordReceived(stale) is RecordCommandResult.StaleSequence)
        store.close()
    }

    @Test
    fun credentialIsEncryptedAtRestAndCanBeRecoveredFromAndroidKeystore() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val store = DeviceCredentialStore(context)
        store.clear()
        store.save(credential)

        val storedValues = context.getSharedPreferences("smb_lacak_secure_state", Context.MODE_PRIVATE)
            .all.values.joinToString("|")
        assertFalse(storedValues.contains(credential.credential))
        assertEquals(credential, store.load())
        store.clear()
    }
}
