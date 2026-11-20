package cloud.poptracker.farm

import android.os.Build
import org.json.JSONObject
import java.io.IOException
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import javax.net.ssl.HttpsURLConnection

internal data class Credentials(val origin: String, val id: String, val token: String)

internal fun deviceFields() = JSONObject()
    .put("agent_version", BuildConfig.VERSION_NAME)
    .put("android_version", Build.VERSION.RELEASE)

internal fun heartbeatFields(id: String, battery: Int?, sentAt: String): JSONObject {
    return deviceFields().put("device_id", id).put("battery_percent", battery).put("sent_at", sentAt)
}

internal fun utcNow(): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US)
    .apply { timeZone = TimeZone.getTimeZone("UTC") }.format(Date())

internal fun request(origin: String, route: String, body: JSONObject? = null, token: String? = null): JSONObject {
    return requestJson(origin, route, body, token) ?: throw IOException("Expected response body")
}

internal fun requestJson(origin: String, route: String, body: JSONObject?, token: String?): JSONObject? {
    val http = URL("$origin/api/device/v1/$route").openConnection() as HttpsURLConnection
    try {
        http.connectTimeout = 8_000
        http.readTimeout = 10_000
        http.instanceFollowRedirects = false
        http.useCaches = false
        http.setRequestProperty("Accept", "application/json")
        if (token != null) http.setRequestProperty("Authorization", "Bearer $token")
        if (body != null) {
            http.requestMethod = "POST"
            http.doOutput = true
            http.setRequestProperty("Content-Type", "application/json")
            val bytes = body.toString().toByteArray(Charsets.UTF_8)
            http.setFixedLengthStreamingMode(bytes.size)
            http.outputStream.use { it.write(bytes) }
        }
        val status = http.responseCode
        if (status == 204) return null
        if (status !in 200..299) {
            val reason = if (status == 409) http.errorStream?.use { input ->
                val bytes = ByteArray(512)
                var length = 0
                while (length < bytes.size) {
                    val n = input.read(bytes, length, bytes.size - length)
                    if (n < 0) break
                    length += n
                }
                runCatching { JSONObject(String(bytes, 0, length, Charsets.UTF_8)).optString("error") }.getOrNull()
            } else null
            throw ApiFailure(status, reason)
        }
        // PHP can escape Unicode in the task envelope beyond the 32 KiB compact payload limit.
        val responseLimit = if (route == "tasks/claim") 128 * 1024 else 8192
        val buffer = java.io.ByteArrayOutputStream()
        http.inputStream.use { input ->
            val chunk = ByteArray(1024)
            while (true) {
                val n = input.read(chunk)
                if (n < 0) break
                if (buffer.size() + n > responseLimit) throw IOException("Response too large")
                buffer.write(chunk, 0, n)
            }
        }
        return JSONObject(buffer.toString("UTF-8"))
    } finally {
        http.disconnect()
    }
}
