# RegionSourceProvider for research APK

This ContentProvider must be built **into** the research TikTok APK
`com.zhiliaoapp.musically.poptrackergeo` (the one that contains `FullAuthorWorker`).

Farm Agent and the research APK are signed with different certificates.
`GeoExecutorProvider` no longer requires a signature match for this package.

## Contract

Authority: `cloud.poptracker.farm.regionsource`

### ready_v1
Returns Bundle with `ready = true` only when:
- the process is in the foreground,
- the TikTok client (`ReuseAudioApi`) is available,
- no concurrent batch is running.

### observe_batch_v1
Input extras:
- `batch` (String) — JSON identical to the payload of a `FRESH_AUTHOR_GEO_V1` task
- `scope_sha256` (String)
- `nonce` (String)

Output Bundle key `response` (String) must be JSON:

```json
{
  "version": 1,
  "request_id": "<same as batch>",
  "nonce": "<echo>",
  "scope_sha256": "<echo>",
  "observations": [
    {
      "uid": "...",
      "vid": "...",
      "field": "author.region",
      "raw_region": "ZA",
      "typed_region": "ZA",
      "observed_ms": 1732123456789,
      "evidence_sha256": "<64 hex>"
    }
  ]
}
```

`raw_region` / `typed_region` may be null only for targets (never for controls).
Controls must match the expected countries (AU / ZA).

## Integration point

Inside `observe_batch_v1` call the existing path from `FullAuthorWorker`:

1. Parse `members` into a `Group` of `Participant`s.
2. Call `execute(factory, group)` (or a thin wrapper).
3. From the resulting `Outcome` collect `target_geo_result` / model + raw records.
4. Build the `observations` array and return it.

Do **not** rely on the file-based READY/GO protocol for this path.
