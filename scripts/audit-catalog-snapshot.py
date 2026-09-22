#!/usr/bin/env python3
"""Offline, read-only consistency audit of audit-catalog-export.php JSONL snapshots.

Rules flag candidates; they never rename, merge, delete or publish profiles.
Usage: python3 scripts/audit-catalog-snapshot.py snapshot.jsonl.gz output_directory
"""
import argparse
import collections
import difflib
import gzip
import hashlib
import html
import json
import re
from pathlib import Path
from urllib.parse import urlparse

TRANSLIT = dict(zip('абвгґдеєжзиіїйклмнопрстуфхцчшщъыьэюя',
    ['a','b','v','h','g','d','e','ie','zh','z','y','i','i','i','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','iu','ia']))
GENERIC = set('центр centr tsentr компанія kompaniia kompaniya company груп group hrup група hrupa магазин mahazyn magazin интернет internet інтернет клініка klinika kliniki клініки салон salon послуги posluhy uslugi услуги бюро biuro byuro адвокат advokat адвокатське advokatske ресторан restoran кафе kafe кава kava студія studiia студия studiia стоматологія stomatolohiia стоматологічний stomatolohichnyi ветеринарна veterynarna клініка klinika будівельні budivelni матеріали materialy товари tovary та ta і i у u в v на na для dlia з z тзов tzov тов tov пп pp фоп fop юридична yurydychna iurydychna медичний medychnyi сервіс servis service приватний pryvatnyi кабінет kabinet нотаріус notarius'.split())

def normalize(value):
    text = html.unescape(re.sub('<[^>]*>', ' ', str(value or ''))).lower()
    text = ''.join(TRANSLIT.get(c, c) for c in text)
    # Collapse common alternative transliterations, not spelling differences.
    text = text.replace('kh', 'h').replace('zh', 'z').replace('shch', 'sh').replace('ts', 'c').replace('y', 'i')
    return ' '.join(re.findall('[a-z0-9]+', text))

STOP = {normalize(v) for v in GENERIC}

def tokens(value):
    return {t for t in normalize(value).split() if len(t) > 2 and t not in STOP and not t.isdigit()}

def compatible(a, b):
    na, nb = normalize(a), normalize(b)
    if not na or not nb:
        return True  # Unknown is not a conflict.
    if na == nb or (len(na) > 8 and na in nb) or (len(nb) > 8 and nb in na):
        return True
    # First comma-separated component usually contains the brand.
    ba, bb = normalize(str(a).split(',')[0]), normalize(str(b).split(',')[0])
    if len(ba) > 3 and ba == bb:
        return True
    ta, tb = tokens(a), tokens(b)
    common = ta & tb
    if common and len(common) / min(len(ta), len(tb)) >= .5:
        return True
    return difflib.SequenceMatcher(None, na, nb).ratio() >= .68

SECTORS = {
    'legal': r'адвокат|адвокатс|юридич|юридичес|нотарі|нотари|advokat|notarius|yurid|iurid',
    'dental': r'стоматолог|зубн|дентал|dent(?:al|ist)|stomatolog',
    'veterinary': r'ветеринар|веткліні|ветклини|зоокліні|veterinar|veterynar',
    'food': r'ресторан|кав[’\x27]?ярн|кофейн|піцері|пиццери|restoran|restaurant|(?:^|\W)кафе(?:\W|$)',
    'construction': r'сантехнік|сантехник|будівельн|строительн|металопласт|будматері|стройматер|магазин.*(?:двер|вікон|вікна)|budivel|santehnik',
    'beauty': r'перукар|парикмах|салон краси|салон красоты|барбершоп|манікюр|маникюр|perukarn|barbershop',
    'education': r'автошкол|мовн[аі] школ|языков.*школ|avtoshkol',
    'property': r'агентств.*нерухом|агентств.*недвиж|ріелтор|риэлтор|rieltor',
}
COMPILED = {k: re.compile(v, re.I) for k, v in SECTORS.items()}
REVIEW_MARKERS = {
    'legal': ['адвокат', 'юридич', 'юридичес', 'нотарі', 'нотари'],
    'dental': ['стоматолог', 'зубн', 'дентал'],
    'veterinary': ['ветеринар', 'веткліні', 'ветклини'],
    'food': ['ресторан', 'кав’ярн', "кав'ярн", 'кофейн', 'піцері', 'пиццери'],
    'construction': ['сантехнік', 'сантехник', 'будівельн', 'строительн', 'металопласт', 'будматері', 'стройматер'],
    'beauty': ['перукар', 'парикмах', 'салон краси', 'салон красоты', 'барбершоп', 'манікюр', 'маникюр'],
    'education': ['автошкол', 'мовна школ', 'мовні школ'],
    'property': ['ріелтор', 'риэлтор', 'агентство нерухом', 'агентство недвиж'],
}
ROOT_SECTOR = {42:'legal', 99:'construction', 100:'education', 102:'veterinary', 106:'property', 118:'food', 120:'beauty'}

def sectors(text):
    return {k for k, rx in COMPILED.items() if rx.search(str(text or ''))}

def incompatible_sectors(a, b):
    # Veterinary dentistry and medical cosmetology are legitimate combinations.
    if not a or not b or a & b:
        return False
    if a | b <= {'dental', 'veterinary'}:
        return False
    return True

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    profiles, categories, services = {}, {}, {}
    pc, ps, sources = collections.defaultdict(list), collections.defaultdict(list), collections.defaultdict(list)
    counts, review_counts, review_ratings = collections.Counter(), collections.Counter(), collections.Counter()
    review_sectors, review_samples = collections.defaultdict(collections.Counter), collections.defaultdict(dict)
    duplicate_bodies = collections.defaultdict(list)
    duplicate_hashes = collections.defaultdict(list)
    missing_review_source = collections.Counter()
    source_phone_usage = collections.defaultdict(list)
    orphan_reviews = collections.Counter()
    completed = False
    meta = {}
    with gzip.open(args.snapshot, 'rt') as stream:
        for line in stream:
            record = json.loads(line)
            table, row = record['table'], record['row']
            counts[table] += 1
            if table == '_meta':
                meta = row
            elif table == '_complete':
                completed = True
            elif table == 'profiles':
                profiles[row['id']] = row
            elif table == 'categories':
                categories[row['id']] = row
            elif table == 'category_services':
                services[row['id']] = row
            elif table == 'profile_category':
                pc[row['profile_id']].append(row)
            elif table == 'profile_category_service':
                ps[row['profile_id']].append(row)
            elif table == 'profile_data_sources':
                sources[row['profile_id']].append(row)
                detail = row.get('found_fields', {}).get('detail', {}) or {}
                for phone in detail.get('phones', []):
                    digits = re.sub(r'\D', '', str(phone))
                    if len(digits) >= 9:
                        source_phone_usage[digits].append((row['id'], row['profile_id'], row['title']))
            elif table == 'profile_reviews':
                pid = row['profile_id']
                if pid not in profiles:
                    orphan_reviews[pid] += 1
                if row['status'] != 'published':
                    continue
                review_counts[pid] += 1
                review_ratings[pid] += int(row['rating'])
                if row.get('external_source_type') and not row.get('external_source_url'):
                    missing_review_source[pid] += 1
                body = re.sub(r'\s+', ' ', str(row.get('body') or '')).strip()
                lower_body = body.lower()
                for sector, markers in REVIEW_MARKERS.items():
                    if not any(marker in lower_body for marker in markers):
                        continue
                    review_sectors[pid][sector] += 1
                    review_samples[pid].setdefault(sector, {'review_id': row['id'], 'excerpt': body[:350]})
                if len(body) >= 100:
                    duplicate_bodies[hashlib.sha256(body.lower().encode()).hexdigest()].append((pid, row['id']))
                if row.get('external_review_hash'):
                    duplicate_hashes[row['external_review_hash']].append((pid, row['id']))
                if counts[table] % 100000 == 0:
                    print(f"Scanned review rows: {counts[table]}", flush=True)
            if table == '_count':
                if counts[row['table']] != row['count']:
                    raise ValueError('Snapshot count mismatch: '+row['table'])
                print(f"Read {row['table']}: {row['count']}", flush=True)
    if not completed:
        raise ValueError('Snapshot is incomplete; refusing to issue a full-audit report.')

    issues = []
    rating_differences = 0
    def issue(pid, kind, confidence, evidence):
        p = profiles.get(pid, {})
        issues.append({'profile_id': pid, 'name': p.get('name'), 'url': 'https://mydovira.com/profiles/'+p['slug'] if p else None,
                       'visible': p.get('status') == 'active' and bool(p.get('is_published')) and bool(p.get('show_in_catalog')),
                       'kind': kind, 'confidence': confidence, 'evidence': evidence})

    multi_source_profiles = 0
    for pid, p in profiles.items():
        links = pc[pid]
        cats = [categories[r['category_id']] for r in links if r['category_id'] in categories]
        roots = {c['parent_id'] or c['id'] for c in cats}
        primary = [r['category_id'] for r in links if r['is_primary']]
        if len(primary) != 1:
            issue(pid, 'primary_category_count', 'structural', {'primary_category_ids': primary, 'category_ids': [c['id'] for c in cats]})
        if len(roots) > 1:
            issue(pid, 'multiple_category_families', 'review', {'categories': [c['name'] for c in cats], 'root_ids': sorted(roots)})
        name_sectors = sectors(p['name'])
        category_sectors = {ROOT_SECTOR[r] for r in roots if r in ROOT_SECTOR}
        if any(c['id'] == 111 for c in cats):
            category_sectors.add('dental')
        if incompatible_sectors(name_sectors, category_sectors):
            issue(pid, 'name_category_conflict', 'review', {'name_sectors': sorted(name_sectors), 'categories': [c['name'] for c in cats]})

        foreign_services = []
        for relation in ps[pid]:
            service = services.get(relation['category_service_id'])
            if not service:
                issue(pid, 'missing_service_reference', 'structural', relation)
                continue
            cat = categories.get(service['category_id'], {})
            root = cat.get('parent_id') or cat.get('id')
            if root and roots and root not in roots:
                foreign_services.append({'service_id': service['id'], 'name': service['name'], 'category': cat.get('name')})
        if foreign_services:
            issue(pid, 'services_outside_category_family', 'review', {'categories': [c['name'] for c in cats], 'services': foreign_services})

        imports = [s for s in sources[pid] if s['source_type'] == 'top20_import']
        titles = [(s, re.sub(r'^Top20 import\s*[·:]?\s*', '', str(s['title'] or ''))) for s in imports]
        if len(titles) > 1:
            multi_source_profiles += 1
        conflicting = [(s, title) for s, title in titles if not compatible(p['name'], title)]
        if conflicting:
            evidence = [{'source_id': s['id'], 'name': title, 'url': s['url'], 'fetched_at': s['fetched_at']} for s, title in conflicting]
            cross_sector = any(incompatible_sectors(name_sectors, sectors(title)) for _, title in conflicting)
            issue(pid, 'different_business_source_names', 'high' if cross_sector else 'review', {'current_name': p['name'], 'conflicting_sources': evidence, 'total_top20_sources': len(imports)})

            for field in ['description', 'description_original', 'short_description', 'ai_review_summary', 'dossier', 'seo_title', 'seo_description']:
                content = p.get(field) or ''
                if field == 'ai_review_summary':
                    try:
                        value = json.loads(content) if isinstance(content, str) else content
                        content = json.dumps(value, ensure_ascii=False)
                    except (ValueError, TypeError):
                        pass
                lead = normalize(str(content)[:1200])
                matches = []
                for source, title in conflicting:
                    brand = str(title).split(',')[0]
                    needle = normalize(brand)
                    # A specific brand from a conflicting source is stronger than a generic sector word.
                    if len(needle) >= 6 and tokens(brand) and needle in lead:
                        matches.append({'name': title, 'source_id': source['id'], 'source_url': source['url']})
                if matches:
                    confidence = 'high' if field != 'description_original' and any(incompatible_sectors(name_sectors, sectors(m['name'])) for m in matches) else 'review'
                    issue(pid, 'content_mentions_other_source_business', confidence, {'field': field, 'matches': matches, 'excerpt': re.sub('<[^>]+>', ' ', str(content))[:500]})

        if not compatible(p['name'], re.sub(r'-\d+$', '', p['slug'])):
            issue(pid, 'name_slug_divergence', 'review', {'name': p['name'], 'slug': p['slug']})

        for sector, n in review_sectors[pid].items():
            if n >= 5 and n / review_counts[pid] >= .4 and incompatible_sectors(name_sectors, {sector}):
                issue(pid, 'review_topic_name_conflict', 'review', {'name_sectors': sorted(name_sectors), 'review_sector': sector, 'matching_reviews': n, 'published_reviews': review_counts[pid], 'sample': review_samples[pid][sector]})
        if int(p['reviews_count'] or 0) != review_counts[pid]:
            issue(pid, 'review_count_mismatch', 'structural', {'stored': p['reviews_count'], 'published_rows': review_counts[pid]})
        average = round(review_ratings[pid] / review_counts[pid], 2) if review_counts[pid] else 0
        if abs(float(p['rating_avg'] or 0) - average) > .011:
            # ProfileRatingCalculator intentionally writes a weighted rating.
            # A difference from the arithmetic mean is NOT a data defect.
            rating_differences += 1

    def duplicates(groups, kind):
        cross = []
        for key, refs in groups.items():
            ids = sorted({pid for pid, _ in refs})
            if len(ids) > 1:
                cross.append({'fingerprint': key, 'profile_ids': ids, 'review_ids': [rid for _, rid in refs]})
        (args.output/(kind+'.json')).write_text(json.dumps(cross, ensure_ascii=False, indent=2))
        return len(cross), len({pid for row in cross for pid in row['profile_ids']})
    duplicate_text_count, duplicate_text_profiles = duplicates(duplicate_bodies, 'duplicate-review-texts')
    duplicate_hash_count, duplicate_hash_profiles = duplicates(duplicate_hashes, 'duplicate-external-review-hashes')

    shared_phones = []
    for phone, usage in source_phone_usage.items():
        source_ids = {r[0] for r in usage}
        if len(source_ids) >= 3:
            shared_phones.append({'phone': phone, 'source_count': len(source_ids), 'profile_ids': sorted({pid for _,pid,_ in usage if pid in profiles}), 'source_ids': sorted(source_ids), 'names': sorted({name for _,_,name in usage})})
    shared_phones.sort(key=lambda r: -r['source_count'])
    (args.output/'shared-source-phones.json').write_text(json.dumps(shared_phones, ensure_ascii=False, indent=2))

    by_profile = collections.defaultdict(list)
    for row in issues:
        by_profile[row['profile_id']].append(row)
    high = {pid for pid, rows in by_profile.items() if any(r['confidence'] == 'high' for r in rows)}
    visible = {pid for pid, p in profiles.items() if p['status'] == 'active' and p['is_published'] and p['show_in_catalog']}
    summary = {'snapshot': meta, 'coverage': {k:v for k,v in counts.items() if not k.startswith('_')},
        'visible_profiles': len(visible), 'flagged_profiles': len(by_profile), 'flagged_visible_profiles': len(set(by_profile)&visible),
        'high_confidence_candidates': len(high), 'high_confidence_visible_candidates': len(high&visible),
        'profiles_with_multiple_top20_sources': multi_source_profiles,
        'issues_by_kind': dict(collections.Counter(r['kind'] for r in issues)),
        'profiles_by_kind': {kind: len({r['profile_id'] for r in issues if r['kind'] == kind}) for kind in sorted({r['kind'] for r in issues})},
        'published_reviews': sum(review_counts.values()), 'external_published_reviews_without_source_url': sum(missing_review_source.values()),
        'weighted_rating_differences_not_flagged': rating_differences,
        'duplicate_text_groups_100_chars': duplicate_text_count, 'duplicate_text_profiles': duplicate_text_profiles,
        'duplicate_external_hash_groups': duplicate_hash_count, 'duplicate_external_hash_profiles': duplicate_hash_profiles,
        'orphan_review_count': sum(orphan_reviews.values()), 'orphan_or_unassigned_source_rows': sum(len(rows) for pid,rows in sources.items() if pid not in profiles)}
    (args.output/'summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2))
    with (args.output/'issues.jsonl').open('w') as stream:
        for row in issues:
            stream.write(json.dumps(row, ensure_ascii=False)+'\n')
    with (args.output/'profile-evidence.jsonl').open('w') as stream:
        for pid in sorted(by_profile):
            p = profiles[pid]
            stream.write(json.dumps({'profile_id': pid, 'name': p['name'], 'slug': p['slug'], 'city': p['city'],
                'categories': [categories.get(r['category_id'], {}) for r in pc[pid]],
                'sources': sources[pid], 'issues': by_profile[pid]}, ensure_ascii=False)+'\n')
    lines = ['# Профілі для перевірки', '',
        'Автоматичні сигнали не є рішенням про видалення чи перейменування. `high` — сильна суперечність між полями/джерелами; `review` — евристика, можлива законна зміна бренду або філія; `structural` — перевірювана розбіжність зв’язків чи лічильників.', '',
        '| ID | Профіль | Місто | Публічний | Сигнали |', '|---|---|---|---|---|']
    for pid in sorted(by_profile, key=lambda pid: (pid not in high, pid not in visible, -len(by_profile[pid]), pid)):
        p = profiles[pid]
        labels = ', '.join(sorted({r['kind'] for r in by_profile[pid]}))
        name = p['name'].replace('|','/').replace('\n',' ')
        lines.append(f"| {pid} | [{name}](https://mydovira.com/profiles/{p['slug']}) | {p['city'] or ''} | {'так' if pid in visible else 'ні'} | {labels} |")
    (args.output/'profiles-to-review.md').write_text('\n'.join(lines)+'\n')
    print(json.dumps(summary, ensure_ascii=False, indent=2))

if __name__ == '__main__':
    main()
