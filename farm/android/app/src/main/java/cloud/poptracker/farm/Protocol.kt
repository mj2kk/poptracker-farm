package cloud.poptracker.farm

import java.io.IOException
import java.net.URI
import kotlin.random.Random

internal const val DEFAULT_ORIGIN = "https://7s-01d.online"

internal fun validatedOrigin(raw: String): String {
    val uri = URI(raw.trim())
    require(uri.scheme.equals("https", true) && !uri.host.isNullOrBlank() &&
        uri.userInfo == null && uri.rawQuery == null && uri.rawFragment == null &&
        uri.rawPath.orEmpty() in listOf("", "/") && (uri.port == -1 || uri.port in 1..65535))
    return URI("https", null, uri.host, uri.port, null, null, null).toASCIIString()
}

internal fun retryMillis(failures: Int): Long {
    val cap = minOf(60_000L, 5_000L shl failures.coerceIn(0, 4))
    return Random.nextLong(cap / 2, cap + 1)
}

internal class ApiFailure(val status: Int, val reason: String? = null) : IOException(when (status) {
    401 -> "Сервер отклонил токен. Забудьте устройство и зарегистрируйтесь снова."
    403 -> "Сервер отклонил код или идентификатор устройства."
    429 -> "Слишком много запросов. Подождите минуту."
    else -> "Ошибка сервера HTTP $status."
})

internal fun taskPollDelay(error: Throwable?, failures: Int, pendingReply: Boolean = false): Long = when {
    error == null -> 12_000L
    error is ApiFailure && error.status == 429 -> 60_000L
    pendingReply -> minOf(retryMillis(failures), 12_000L)
    else -> retryMillis(failures)
}
