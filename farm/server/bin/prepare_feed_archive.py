"""Build a minimized deployment copy from already-audited, closed Apify exports (offline)."""
import argparse
import hashlib
import json
import re
import shutil
from pathlib import Path

FIELDS = (
    "videoId", "videoAuthorId", "videoAuthorUsername", "videoCreatedAt", "country",
    "videoUrl", "videoDescription", "hashtags", "musicId", "musicTitle", "musicAuthor",
    "isPhotoPost", "videoPlayCount", "videoLikeCount", "videoCommentCount", "videoShareCount",
    "videoSaveCount", "category", "scrapedAt", "language", "durationSeconds",
)


def read_verified(path, digest):
    source = path.read_bytes()
    if hashlib.sha256(source).hexdigest() != digest:
        raise ValueError(f"Digest mismatch in {path.name}")
    return json.loads(source)


def prepare(source, destination, audit_sha):
    if destination.exists():
        raise ValueError("Destination must be new (the source archive is immutable)")
    name = "independent-final-source-candidate-audit.json"
    audit = read_verified(source / name, audit_sha)
    if audit["status"] != f"PASS_INDEPENDENT_{audit['experiment']}_FULL_CLOSED_SOURCE_CANDIDATE_AUDIT":
        raise ValueError("Independent closed-source verification required")
    handoff_name = "offline-final-handoff/result.json"
    proofs = [v for k, v in audit["checked_file_sha256"].items() if k.endswith("/" + handoff_name)]
    if len(proofs) != 1:
        raise ValueError("Missing final handoff proof")
    handoff = read_verified(source / handoff_name, proofs[0])
    if handoff["status"] != "PASS_PRIVATE_PENDING_CANDIDATE_HANDOFF":
        raise ValueError("Incomplete handoff")
    refs = {r["batch_id"]: r for r in handoff["exports"]}
    approved = {}
    destination.mkdir(parents=True)
    (destination / "offline-final-handoff").mkdir()
    shutil.copyfile(source / name, destination / name)
    shutil.copyfile(source / handoff_name, destination / handoff_name)
    for entry in audit["source_exports"]:
        bid, dataset = entry["batch_id"], entry["dataset_id"]
        if not re.fullmatch(r"b[0-9]{2,6}", bid) or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", dataset):
            raise ValueError("Unexpected batch or dataset identifier")
        ref = refs[bid]
        if ref["dataset_id"] != dataset or dataset in approved or ref["run_id"] != entry["run_id"]:
            raise ValueError("Dataset identity conflict")
        origin = source / "batches" / bid
        out = destination / "batches" / bid
        out.mkdir(parents=True)
        for key, filename in (("closed", "closed.json"), ("provenance", "export-provenance.json")):
            read_verified(origin / filename, ref[key]["sha256"])
            shutil.copyfile(origin / filename, out / filename)
        rows = read_verified(origin / "run.json", entry["export_sha256"])
        if not isinstance(rows, list) or len(rows) != entry["rows"] or ref["export"]["sha256"] != entry["export_sha256"]:
            raise ValueError("Row count or binding differs")
        reduced = []
        for row in rows:
            # Keep missing, null and zero distinct; preserve integer identifiers without binary floats.
            clean = {k: row[k] for k in FIELDS if k in row}
            url = clean.get("videoUrl")
            if url is not None and not re.fullmatch(r"https://(?:www\.)?tiktok\.com/@[A-Za-z0-9._-]+/(?:video|photo)/[0-9]{1,32}(?:/)?", url):
                raise ValueError("Unexpected provider video URL")
            reduced.append(clean)
        raw = (json.dumps(reduced, ensure_ascii=False, separators=(",", ":"), allow_nan=False) + "\n").encode()
        if re.search(rb"(?:refresh_token|access_token|x-signature|api[_-]?key)=[^&\s\x22]{5,}|\bapify_api_[A-Za-z0-9_-]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----", raw, re.I):
            raise ValueError("Credential-like material in projected fields")
        (out / "verified-rows.json").write_bytes(raw)
        approved[dataset] = {"run_id": entry["run_id"], "source_sha256": entry["export_sha256"],
                             "projection_sha256": hashlib.sha256(raw).hexdigest()}
    if set(refs) != {r["batch_id"] for r in audit["source_exports"]}:
        raise ValueError("Incomplete closed archive")
    return {"protocol": "closed-feed-projection-v1", "datasets": approved}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("new_destination", type=Path)
    parser.add_argument("new_trust_file", type=Path)
    parser.add_argument("--approved-audit-sha256", required=True)
    args = parser.parse_args()
    if args.new_trust_file.exists():
        parser.error("Refusing to replace an existing projection approval")
    trust = prepare(args.source, args.new_destination, args.approved_audit_sha256)
    args.new_trust_file.write_text(json.dumps(trust, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print(f"Verified and projected {len(trust['datasets'])} closed datasets")
