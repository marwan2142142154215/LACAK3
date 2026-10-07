package com.smb.lacak.agent

import kotlin.math.min
import kotlin.random.Random

object DeviceReconnectPolicy {
    const val MIN_RETRY_MS = 3_000L
    const val MAX_RETRY_MS = 5 * 60_000L

    fun nextBackoff(currentMs: Long): Long {
        val bounded = currentMs.coerceIn(MIN_RETRY_MS, MAX_RETRY_MS)
        return if (bounded >= MAX_RETRY_MS / 2) MAX_RETRY_MS else min(bounded * 2, MAX_RETRY_MS)
    }

    fun jitteredDelay(baseMs: Long, jitter: Double = Random.nextDouble(0.75, 1.25)): Long {
        require(jitter in 0.75..1.25)
        return (baseMs.coerceIn(MIN_RETRY_MS, MAX_RETRY_MS) * jitter).toLong()
    }
}
