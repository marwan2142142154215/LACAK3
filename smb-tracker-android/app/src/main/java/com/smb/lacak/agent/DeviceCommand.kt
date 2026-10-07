package com.smb.lacak.agent

import org.json.JSONObject

data class DeviceCommand(
    val commandId: String,
    val deviceId: String,
    val deviceSessionId: String,
    val commandType: String,
    val sequence: Long,
    val payload: JSONObject,
    val expiresAtEpochMillis: Long,
    val fingerprint: String,
)

data class CommandReceipt(
    val commandId: String,
    val deviceId: String,
    val sequence: Long,
    val fingerprint: String,
    val status: String,
    val resultCode: String?,
    val resultMessage: String?,
)

sealed interface RecordCommandResult {
    data class Stored(val receipt: CommandReceipt) : RecordCommandResult
    data class Duplicate(val receipt: CommandReceipt) : RecordCommandResult
    data class StaleSequence(val highestSequence: Long) : RecordCommandResult
}

class InvalidDeviceCommand(val code: String, message: String) : IllegalArgumentException(message)
