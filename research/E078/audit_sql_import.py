#!/usr/bin/env python3
"""Offline independent all-six-table/inserted-pair/idempotence verification. No SQL calls."""
import argparse
import hashlib
import json
from pathlib import Path

TABLES = ['authors', 'videos', 'comments', 'author_geo_evidence', 'scans', 'observations']
def canonical(value): return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
def need(value, reason):
    if not value: raise ValueError(reason)


def audit(payload, origin, before, after, commit, payload_sha, binding_sha, idempotence=None):
    need(payload['schema'] == 'poptracker.E078.new-ZA-author-and-native-geo.v1', 'payload_schema')
    need(canonical(payload['origin_baseline']) == canonical(origin), 'external_frozen_origin')
    rows = payload['rows']; authors = {r['author']['id']: r['author'] for r in rows}
    geo = {r['geo']['evidence_key']: r['geo'] for r in rows}
    need(len(rows) == len(authors) == len(geo) and all(r['geo']['country_code'] == 'ZA' and r['geo']['video_id'] is None for r in rows), 'unique_ZA_only_insert_pairs')
    n = len(rows); b, a = before['snapshot'], after['snapshot']
    for document in [before, after, commit] + ([idempotence] if idempotence else []):
        need(document['experiment'] == 'E078' and document['payload_sha256'] == payload_sha
             and document['source_binding_sha256'] == binding_sha, 'exact_receipt_source_payload_binding')
    def verify(snapshot, selected):
        need(set(snapshot['full_table_sha256']) == set(TABLES), 'full_hash_scope')
        for table in TABLES:
            need(snapshot['counts'][table] == origin['counts'][table] + (selected if table in {'authors', 'author_geo_evidence'} else 0), 'origin_count_' + table)
            if table not in {'authors', 'author_geo_evidence'}:
                need(snapshot['full_table_sha256'][table] == origin['full_table_sha256'][table], 'protected_' + table)
        need(snapshot['counts']['valid_mobile_authors'] == origin['counts']['valid_mobile_authors'] + selected, 'native_known_count')
        need(snapshot['old_authors_excluding_payload_ids_sha256'] == origin['full_table_sha256']['authors'], 'old_author_full_rows')
        need(snapshot['old_geo_excluding_payload_keys_sha256'] == origin['full_table_sha256']['author_geo_evidence'], 'old_geo_full_rows')
        need(snapshot['other_geo_for_selected_authors'] == [], 'other_selected_author_geo')
        actual_authors = {r['id']: r for r in snapshot['selected_authors']}
        actual_geo = {r['evidence_key']: r for r in snapshot['selected_evidence']}
        need(len(actual_authors) == len(snapshot['selected_authors']) == selected
             and len(actual_geo) == len(snapshot['selected_evidence']) == selected, 'selected_unique_counts')
        need(set(actual_authors) <= set(authors) and set(actual_geo) <= set(geo), 'selected_ID_scope')
        for uid, actual in actual_authors.items(): need(canonical(actual) == canonical(authors[uid]), 'exact_new_author_row')
        ids = []
        for key, actual in actual_geo.items():
            expected = geo[key]; ids.append(str(actual['id']))
            need(str(actual['id']).isdigit() and int(actual['id']) > 0, 'geo_PK')
            for field in ['author_id', 'video_id', 'country_code', 'kind', 'observed_at', 'summary', 'evidence_key']:
                need(actual[field] == expected[field], 'exact_geo_' + field)
            source = json.loads(actual['sources']) if isinstance(actual['sources'], str) else actual['sources']
            need(canonical(source) == canonical(expected['sources']), 'exact_full_native_provider_provenance')
        need(len(ids) == len(set(ids)), 'unique_geo_PK')
    verify(b, 0); verify(a, n)
    need(commit['mode'] == 'committed' and commit['inserted_authors'] == commit['inserted_geo'] == n
         and commit['already_present'] == 0 and commit['SQL_writes'] == 2 * n, 'single_commit_accounting')
    need(isinstance(commit.get('authorization_sha256'), str) and len(commit['authorization_sha256']) == 64 and commit.get('authorization_intent'), 'once_authorization_chain')
    need(canonical(commit['before']) == canonical(b) and canonical(commit['snapshot']) == canonical(a), 'commit_vs_independent_exports')
    for field in ['existing_rows_updated', 'videos_inserted', 'observations_inserted', 'scans_inserted']:
        need(commit[field] == 0, 'forbidden_mutation_' + field)
    if idempotence:
        need(idempotence['rows_to_insert'] == 0 and idempotence['already_present'] == n
             and idempotence['SQL_writes'] == 0 and canonical(idempotence['snapshot']) == canonical(a), 'read_only_idempotence')
    return dict(status='PASS_INDEPENDENT_E078_NEW_ZA_AUTHOR_AND_GEO_SQL_AUDIT', new_authors=n, new_geo_rows=n,
                old_authors_and_old_geo_full_row_complement_hashes_preserved=True, four_other_full_table_hashes_preserved=True,
                new_rows_exact_provider_native_provenance=True, idempotence='PASS_READ_ONLY' if idempotence else 'NOT_PROVIDED',
                SQL_network_phone_calls=0, payload_sha256=payload_sha, source_binding_sha256=binding_sha)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ['payload', 'source-binding', 'before', 'after', 'commit', 'output', 'authorization', 'parent-authorization', 'commit-intent']:
        parser.add_argument('--' + name, type=Path, required=True)
    parser.add_argument('--source-binding-sha256', required=True)
    parser.add_argument('--authorization-sha256', required=True)
    parser.add_argument('--parent-authorization-sha256', required=True)
    parser.add_argument('--commit-intent-sha256', required=True)
    parser.add_argument('--remote-intent-path', required=True)
    parser.add_argument('--idempotence', type=Path)
    args = parser.parse_args(); load = lambda p: json.loads(p.read_bytes())
    bindingraw = args.source_binding.read_bytes(); need(hashlib.sha256(bindingraw).hexdigest() == args.source_binding_sha256, 'external_binding_SHA')
    binding = json.loads(bindingraw); raw = args.payload.read_bytes(); payload = json.loads(raw)
    need(payload['source_binding_sha256'] == args.source_binding_sha256, 'payload_binding_SHA')
    for name, digest in binding['files'].items():
        need(hashlib.sha256((args.source_binding.parent / name).read_bytes()).hexdigest() == digest, 'external_original_file_SHA_' + name)
    origin = load(args.source_binding.parent / 'SQL-origin-baseline.json')['snapshot']
    payload_sha = hashlib.sha256(raw).hexdigest(); commit = load(args.commit)
    need(hashlib.sha256(args.authorization.read_bytes()).hexdigest() == args.authorization_sha256
         and hashlib.sha256(args.parent_authorization.read_bytes()).hexdigest() == args.parent_authorization_sha256, 'exact_external_authorization_files')
    auth, parent = load(args.authorization), load(args.parent_authorization)
    need(auth.get('schema') == 'poptracker.E078.explicit-new-ZA-author-import.v1' and auth.get('explicit_user_authorized') is True
         and type(auth.get('max_commits')) is int and auth['max_commits'] == 1
         and auth.get('payload_sha256') == payload_sha and auth.get('source_binding_sha256') == args.source_binding_sha256
         and auth.get('baseline_sha256') == binding['baseline_sha256']
         and auth.get('new_author_count') == auth.get('new_geo_count') == len(payload['rows'])
         and auth.get('candidate_discovery_authorization_sha256') == args.parent_authorization_sha256
         and auth.get('standing_geo_authorization_sha256') == '52a14bae9281a6169cd452a38981e3e15455251ad12d1b849853cd57d0763597', 'once_child_authorization_bound_to_payload_origin_parent')
    catalog = load(args.source_binding.parent / 'source-catalog.json')
    need(parent.get('schema') == 'poptracker.author-discovery-and-native-geo.authorization.v1'
         and parent.get('explicit_user_authorized') is True and parent.get('unconfirmed_candidates_hidden') is True
         and parent.get('existing_rows_immutable') is True and parent.get('SQL_write_requires_concrete_payload_and_independent_review') is True
         and parent.get('source_candidate_snapshot_sha256') == catalog['source_candidates']['sha256'], 'parent_candidate_scope')
    need(commit.get('authorization_sha256') == args.authorization_sha256
         and commit.get('parent_authorization_sha256') == args.parent_authorization_sha256, 'actual_commit_authorization_chain')
    intent_path = args.commit_intent
    need(commit.get('authorization_intent') == args.remote_intent_path
         and hashlib.sha256(intent_path.read_bytes()).hexdigest() == args.commit_intent_sha256, 'actual_server_intent_copy_SHA_and_original_remote_path')
    intent = load(intent_path)
    for key, expected in [('payload_sha256', payload_sha), ('source_binding_sha256', args.source_binding_sha256),
                          ('authorization_sha256', args.authorization_sha256), ('parent_authorization_sha256', args.parent_authorization_sha256),
                          ('new_author_count', len(payload['rows'])), ('new_geo_count', len(payload['rows']))]:
        need(intent.get(key) == expected, 'durable_once_intent_' + key)
    result = audit(payload, origin, load(args.before), load(args.after), commit, payload_sha,
                   args.source_binding_sha256, load(args.idempotence) if args.idempotence else None)
    result.update(explicit_parent_and_once_child_authorization_chain_verified=True, once_intent_sha256=hashlib.sha256(intent_path.read_bytes()).hexdigest())
    with args.output.open('x') as stream: json.dump(result, stream, indent=2); stream.write('\n')
    print(json.dumps(result))


if __name__ == '__main__': main()
