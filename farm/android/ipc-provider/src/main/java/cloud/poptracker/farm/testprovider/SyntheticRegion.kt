package cloud.poptracker.farm.testprovider

import cloud.poptracker.farm.ipc.RegionContract as Contract

internal fun callerAllowed(sameSigner: Boolean, uidPackages: Array<String>?): Boolean =
    sameSigner && uidPackages?.singleOrNull() == Contract.CLIENT

internal fun syntheticRegion(version: Int, requestId: String?, operation: String?, authorUid: String?): Map<String, String> {
    if (requestId == null || !Contract.requestIdPattern.matches(requestId)) {
        return mapOf("status" to "error", "code" to "invalid_request_id")
    }
    val error = when {
        version != Contract.VERSION -> "unsupported_version"
        operation != Contract.OPERATION -> "unsupported_operation"
        authorUid != Contract.FIXTURE -> "unsupported_author"
        else -> null
    }
    if (error != null) return mapOf("status" to "error", "request_id" to requestId, "code" to error)
    return mapOf("request_id" to requestId, "status" to "success", "author_uid" to authorUid!!,
        "region" to "ZA", "source" to Contract.SOURCE)
}
