package com.smb.lacak.agent

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

class DeviceCommandReceiptStore(context: Context) : SQLiteOpenHelper(
    context.applicationContext,
    DATABASE_NAME,
    null,
    DATABASE_VERSION,
) {
    @Synchronized
    fun recordReceived(command: DeviceCommand): RecordCommandResult {
        val database = writableDatabase
        database.beginTransaction()
        try {
            database.delete(
                TABLE_RECEIPTS,
                "status IN (?, ?) AND created_at < ?",
                arrayOf(STATUS_SUCCESS, STATUS_FAILED, (System.currentTimeMillis() - RECEIPT_RETENTION_MS).toString()),
            )
            findReceipt(database, command.commandId)?.let {
                database.setTransactionSuccessful()
                return RecordCommandResult.Duplicate(it)
            }

            val highest = database.rawQuery(
                "SELECT highest_sequence FROM command_sequences WHERE device_id = ?",
                arrayOf(command.deviceId),
            ).use { cursor -> if (cursor.moveToFirst()) cursor.getLong(0) else 0L }
            if (command.sequence <= highest) {
                database.setTransactionSuccessful()
                return RecordCommandResult.StaleSequence(highest)
            }

            val now = System.currentTimeMillis()
            val receipt = CommandReceipt(command.commandId, command.deviceId, command.sequence, command.fingerprint, STATUS_RECEIVED, null, null)
            database.insertOrThrow(TABLE_RECEIPTS, null, receiptValues(receipt, now, now))
            database.insertWithOnConflict(
                TABLE_SEQUENCES,
                null,
                ContentValues().apply {
                    put("device_id", command.deviceId)
                    put("highest_sequence", command.sequence)
                },
                SQLiteDatabase.CONFLICT_REPLACE,
            )
            database.setTransactionSuccessful()
            return RecordCommandResult.Stored(receipt)
        } finally {
            database.endTransaction()
        }
    }

    @Synchronized
    fun updateStatus(
        commandId: String,
        status: String,
        resultCode: String? = null,
        resultMessage: String? = null,
    ): CommandReceipt? {
        require(status in RECEIPT_STATUSES) { "Unsupported local command status." }
        val database = writableDatabase
        database.beginTransaction()
        try {
            val current = findReceipt(database, commandId) ?: return null
            if (current.status == status) {
                database.setTransactionSuccessful()
                return current
            }
            val allowed = when (status) {
                STATUS_EXECUTING -> current.status == STATUS_RECEIVED || current.status == STATUS_EXECUTING
                STATUS_SUCCESS, STATUS_FAILED -> current.status == STATUS_RECEIVED || current.status == STATUS_EXECUTING
                else -> false
            }
            if (!allowed) {
                return null
            }

            val updated = current.copy(status = status, resultCode = resultCode, resultMessage = resultMessage)
            database.update(
                TABLE_RECEIPTS,
                receiptValues(updated, null, System.currentTimeMillis()),
                "command_id = ?",
                arrayOf(commandId),
            )
            database.setTransactionSuccessful()
            return updated
        } finally {
            database.endTransaction()
        }
    }

    @Synchronized
    fun find(commandId: String): CommandReceipt? = findReceipt(readableDatabase, commandId)

    override fun onCreate(database: SQLiteDatabase) {
        database.execSQL(
            """CREATE TABLE $TABLE_RECEIPTS (
                command_id TEXT PRIMARY KEY NOT NULL,
                device_id TEXT NOT NULL,
                sequence INTEGER NOT NULL,
                fingerprint TEXT NOT NULL,
                status TEXT NOT NULL,
                result_code TEXT,
                result_message TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(device_id, sequence)
            )""".trimIndent(),
        )
        database.execSQL("CREATE INDEX index_command_receipts_device ON $TABLE_RECEIPTS(device_id, created_at)")
        database.execSQL(
            """CREATE TABLE $TABLE_SEQUENCES (
                device_id TEXT PRIMARY KEY NOT NULL,
                highest_sequence INTEGER NOT NULL
            )""".trimIndent(),
        )
    }

    override fun onUpgrade(database: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        if (oldVersion < 2) {
            database.execSQL("ALTER TABLE $TABLE_RECEIPTS ADD COLUMN fingerprint TEXT NOT NULL DEFAULT ''")
        }
    }

    private fun findReceipt(database: SQLiteDatabase, commandId: String): CommandReceipt? =
        database.query(
            TABLE_RECEIPTS,
            arrayOf("command_id", "device_id", "sequence", "fingerprint", "status", "result_code", "result_message"),
            "command_id = ?",
            arrayOf(commandId),
            null,
            null,
            null,
            "1",
        ).use { cursor ->
            if (!cursor.moveToFirst()) return@use null
            CommandReceipt(
                commandId = cursor.getString(0),
                deviceId = cursor.getString(1),
                sequence = cursor.getLong(2),
                fingerprint = cursor.getString(3),
                status = cursor.getString(4),
                resultCode = cursor.getString(5),
                resultMessage = cursor.getString(6),
            )
        }

    private fun receiptValues(receipt: CommandReceipt, createdAt: Long?, updatedAt: Long): ContentValues =
        ContentValues().apply {
            put("command_id", receipt.commandId)
            put("device_id", receipt.deviceId)
            put("sequence", receipt.sequence)
            put("fingerprint", receipt.fingerprint)
            put("status", receipt.status)
            put("result_code", receipt.resultCode)
            put("result_message", receipt.resultMessage)
            if (createdAt != null) put("created_at", createdAt)
            put("updated_at", updatedAt)
        }

    private companion object {
        const val DATABASE_NAME = "smb_device_command_receipts.db"
        const val DATABASE_VERSION = 2
        const val TABLE_RECEIPTS = "command_receipts"
        const val TABLE_SEQUENCES = "command_sequences"
        const val STATUS_RECEIVED = "RECEIVED"
        const val STATUS_EXECUTING = "EXECUTING"
        const val STATUS_SUCCESS = "SUCCESS"
        const val STATUS_FAILED = "FAILED"
        const val RECEIPT_RETENTION_MS = 90L * 24 * 60 * 60 * 1_000
        val RECEIPT_STATUSES = setOf(STATUS_RECEIVED, STATUS_EXECUTING, STATUS_SUCCESS, STATUS_FAILED)
    }
}
