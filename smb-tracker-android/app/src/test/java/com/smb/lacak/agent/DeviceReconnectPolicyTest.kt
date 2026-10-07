package com.smb.lacak.agent

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DeviceReconnectPolicyTest {
    @Test
    fun `backoff doubles and caps at five minutes`() {
        assertEquals(6_000L, DeviceReconnectPolicy.nextBackoff(3_000L))
        assertEquals(300_000L, DeviceReconnectPolicy.nextBackoff(240_000L))
        assertEquals(300_000L, DeviceReconnectPolicy.nextBackoff(300_000L))
    }

    @Test
    fun `jitter stays bounded by configured range`() {
        val low = DeviceReconnectPolicy.jitteredDelay(10_000L, 0.75)
        val high = DeviceReconnectPolicy.jitteredDelay(10_000L, 1.25)
        assertTrue(low in 7_500L..12_500L)
        assertTrue(high in 7_500L..12_500L)
    }

    @Test(expected = IllegalArgumentException::class)
    fun `jitter outside configured range is rejected`() {
        DeviceReconnectPolicy.jitteredDelay(10_000L, 2.0)
    }
}
