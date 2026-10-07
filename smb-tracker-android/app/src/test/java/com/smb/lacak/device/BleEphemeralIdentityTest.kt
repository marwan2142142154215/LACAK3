package com.smb.lacak.device

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class BleEphemeralIdentityTest {
    @Test
    fun encodesOnlyVersionAndTenByteIdentifier() {
        val identity = "00112233445566778899"
        val encoded = byteArrayOf(BleEphemeralIdentity.PROTOCOL_VERSION) + requireNotNull(BleEphemeralIdentity.bytes(identity))

        assertEquals(BleEphemeralIdentity.PAYLOAD_SIZE, encoded.size)
        assertEquals(identity, BleEphemeralIdentity.fromBytes(encoded))
        assertNull(BleEphemeralIdentity.fromBytes(encoded.copyOf(10)))
        assertNull(BleEphemeralIdentity.bytes("device-id"))
    }
}
