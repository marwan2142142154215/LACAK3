package com.smb.lacak.presentation

import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.test.core.app.ActivityScenario
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class MainActivitySmokeTest {
    @Test
    fun displaysRealRegistrationAndCapabilityScreen() {
        ActivityScenario.launch(MainActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val root = activity.findViewById<View>(com.smb.lacak.R.id.trackerRoot)
                val visibleText = mutableListOf<String>()
                collectText(root, visibleText)

                assertTrue("Tracker screen root is not visible", root.visibility == View.VISIBLE)
                assertTrue("Brand title is missing", visibleText.contains("SMB LACAK"))
                assertTrue("Official activation screen is missing", visibleText.contains("Aktivasi resmi SMB"))
                assertTrue(
                    "Android compatibility note is missing",
                    visibleText.contains("Minimum Android 8.0 (API 26). Capability kamera/lokasi bergantung pada perangkat, permission, OS, dan policy.")
                )
            }
        }
    }

    private fun collectText(view: View, output: MutableList<String>) {
        if (view is TextView) output += view.text.toString()
        if (view is ViewGroup) {
            for (index in 0 until view.childCount) collectText(view.getChildAt(index), output)
        }
    }
}
