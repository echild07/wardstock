# Build Prompt — Periodic Kardia EKG Ingestion and Analysis

## Instructions to the implementation agent

Add periodic personal EKG recording support to Ward's private health-data platform. The system must support KardiaMobile single-lead and KardiaMobile 6L recordings, preserve the original source artifacts, capture symptom and situational context, store recording-level summaries in the existing InfluxDB 2.0 environment, and index metadata in SQLite.

Treat this document as the implementation specification. Do not describe the system as diagnostic, do not infer that a normal Kardia result rules out a cardiac problem, and do not silently convert user observations or automated Kardia determinations into clinician diagnoses.

## Existing platform assumptions

- Ward has Home Assistant with Node-RED and InfluxDB 2.0.
- The broader health platform uses a private API rather than exposing InfluxDB directly to devices.
- Raw HealthKit physiological data uses a dedicated `ward_health_raw` bucket.
- Daily derived metrics use a dedicated `ward_health_daily` bucket.
- SQLite provides relational metadata, synchronization indexes, health-event records, and audit state.
- A private web interface provides upload, review, timelines, charts, and exports.
- Node-RED publishes selected summaries and operational status to Home Assistant.

## Kardia product reference

The design must support both:

### KardiaMobile / KardiaMobile Card

- Single-lead ECG
- Lead I
- Typically a 30-second recording
- Standard determinations may include normal sinus rhythm, possible atrial fibrillation, bradycardia, tachycardia, unreadable, and unclassified

### KardiaMobile 6L

- Six limb leads: I, II, III, aVR, aVL, and aVF
- Typically a 30-second recording
- The same basic automated rhythm determinations
- More detailed clinician review is possible because six views are present

KardiaCare features may add determinations such as sinus rhythm with PVCs, sinus rhythm with supraventricular ectopy, and sinus rhythm with wide QRS. Store determination codes as extensible strings rather than a closed enumeration.

Official references:

- [KardiaMobile 6L](https://store.alivecor.com/products/kardiamobile6l)
- [KardiaMobile single-lead](https://store.alivecor.com/products/kardiamobile)
- [AliveCor Advanced Determinations](https://alivecor.co.uk/blog/advanced-determinations)
- [AliveCor data integrations](https://alivecor.com/data-integration)

## Primary goals

1. Accept periodic Kardia recordings through manual PDF upload.
2. Preserve the untouched original PDF with a cryptographic hash.
3. Extract and normalize date, time, duration, device type, lead configuration, heart rate, automated determination, and report identifiers when present.
4. Require user review before committing extracted information.
5. Capture why Ward took the EKG, symptoms, intensity, activity, posture, and notes.
6. Support optional raw waveform import if a legitimate Kardia export or integration provides it.
7. Keep waveform files outside InfluxDB while storing recording summaries in InfluxDB.
8. Link EKGs to Ward's health events and other physiological data for observational timelines.
9. Support optional clinician interpretations without overwriting Kardia's automated determination.
10. Make every interpretation traceable to its source.

## Non-goals

- Detecting or ruling out myocardial infarction.
- Emergency monitoring or alerting.
- Continuous ECG streaming.
- Reconstructing raw voltage samples from a PDF image.
- Providing an AI-generated medical diagnosis.
- Treating Kardia classifications as definitive diagnoses.
- Scraping or reverse-engineering a consumer Kardia account.
- Requiring an AliveCor enterprise integration for the first release.

AliveCor explicitly states that KardiaMobile does not check for heart attack. The application must display this limitation near every result and symptom-entry flow.

## Expected ingestion paths

### Phase one — manual PDF ingestion

1. Ward records an EKG in the Kardia application.
2. Ward exports or shares the Kardia PDF.
3. Ward uploads the PDF to the private website.
4. The server preserves the original file before parsing.
5. The parser extracts available metadata and report text.
6. The website presents a confirmation form.
7. Ward corrects or completes missing values and adds context.
8. The system stores the confirmed record, artifact metadata, and InfluxDB summary point.

### Phase two — optional structured waveform import

If Kardia provides a legitimate CSV, JSON, binary, SDK, cloud API, or enterprise export:

1. Preserve the original structured export unchanged.
2. Convert a working copy to a documented canonical waveform format.
3. Record source format, sample rate, bit depth, units, and conversion version.
4. Validate the sample count against duration and sampling rate.
5. Store the waveform as compressed Parquet or another efficient columnar format.
6. Never invent missing voltage data or derive a raw waveform from the rendered PDF trace.

The consumer workflow must remain fully useful without raw waveform access. AliveCor advertises PDF and raw time-series formats for enterprise integrations, but this does not imply that consumer accounts expose a public raw-data API.

## Canonical recording object

```json
{
  "schema_version": "1.0",
  "recording_id": "0191c5c0-6310-7000-9000-acde48001122",
  "user_id": "ward",
  "recorded_at": "2026-08-20T10:42:18-04:00",
  "timezone": "America/New_York",
  "duration_seconds": 30.0,

  "device": {
    "manufacturer": "AliveCor",
    "product": "KardiaMobile 6L",
    "model": null,
    "serial_number": null,
    "firmware_version": null,
    "app_name": "Kardia",
    "app_version": null
  },

  "acquisition": {
    "recording_type": "resting_ecg",
    "lead_configuration": "six_lead_limb",
    "leads": ["I", "II", "III", "aVR", "aVL", "aVF"],
    "sampling_rate_hz": null,
    "sample_format": null,
    "sample_bit_depth": null,
    "signal_units": null,
    "body_position": "seated",
    "contact_method": "both_hands_and_left_leg",
    "quality": "acceptable"
  },

  "measurements": {
    "average_heart_rate_bpm": 68,
    "minimum_heart_rate_bpm": null,
    "maximum_heart_rate_bpm": null,
    "pr_ms": null,
    "qrs_ms": null,
    "qt_ms": null,
    "qtc_ms": null
  },

  "automated_interpretation": {
    "provider": "AliveCor Kardia",
    "determination_code": "normal_sinus_rhythm",
    "determination_text": "Normal Sinus Rhythm",
    "algorithm_version": null,
    "confidence": null,
    "is_unclassified": false,
    "is_unreadable": false
  },

  "context": {
    "reason": "periodic_baseline",
    "symptoms_present": false,
    "symptoms": [],
    "subjective_intensity_0_to_10": 0,
    "activity_before_recording": "seated_rest",
    "rest_minutes_before_recording": 5,
    "notes": "Routine periodic sample.",
    "related_health_event_id": null
  },

  "clinician_review": {
    "reviewed": false,
    "reviewed_at": null,
    "reviewer_name": null,
    "reviewer_type": null,
    "interpretation": null,
    "recommended_action": null,
    "source_artifact_id": null
  },

  "artifacts": [],

  "source": {
    "import_method": "manual_pdf_upload",
    "source_system": "Kardia",
    "source_record_id": null,
    "imported_at": "2026-08-20T10:48:00-04:00",
    "confirmed_by_user": true,
    "confirmed_at": "2026-08-20T10:49:00-04:00"
  }
}
```

Unknown technical properties must remain `null`. Do not fill sampling rate, bit depth, voltage units, or algorithm version from generic product specifications unless the actual source export confirms them.

## Controlled values

### `lead_configuration`

- `single_lead_i`
- `six_lead_limb`
- `unknown`

### `quality`

- `acceptable`
- `poor`
- `unreadable`
- `unknown`

### `reason`

- `periodic_baseline`
- `symptom_capture`
- `post_exertion`
- `medication_change`
- `clinician_requested`
- `repeat_after_unreadable`
- `other`

### Suggested symptom codes

- `chest_pressure`
- `chest_pain`
- `left_arm_discomfort`
- `right_arm_discomfort`
- `bilateral_arm_aching`
- `pounding_heartbeat`
- `palpitations`
- `irregular_heartbeat_sensation`
- `shortness_of_breath`
- `dizziness`
- `lightheadedness`
- `fainting`
- `fatigue`
- `sweating`
- `nausea`
- `jitteriness`
- `anxiety_or_dread`
- `other`

Allow multiple symptoms, each with intensity from 0 to 10 and optional onset time. Preserve Ward's words in a free-text note in addition to normalized codes.

### Determination codes

Seed the following values but accept future vendor strings:

- `normal_sinus_rhythm`
- `possible_atrial_fibrillation`
- `bradycardia`
- `tachycardia`
- `sinus_rhythm_with_pvcs`
- `sinus_rhythm_with_sve`
- `sinus_rhythm_with_wide_qrs`
- `unclassified`
- `unreadable`
- `other`

Store the exact vendor text alongside the normalized code.

## Artifact model

Every imported file receives an artifact record:

```json
{
  "artifact_id": "0191c5c0-6310-7000-9000-acde48003344",
  "recording_id": "0191c5c0-6310-7000-9000-acde48001122",
  "artifact_type": "kardia_pdf_report",
  "mime_type": "application/pdf",
  "filename": "kardia_2026-08-20_104218.pdf",
  "byte_size": 123456,
  "sha256": "hex-digest",
  "storage_path": "ecg/2026/08/0191c5c0/report.pdf",
  "original": true,
  "created_at": "2026-08-20T10:48:00-04:00"
}
```

Supported artifact types:

- `kardia_pdf_report`
- `kardia_summary_report`
- `ecg_waveform_original`
- `ecg_waveform_parquet`
- `clinician_review_pdf`
- `other`

Requirements:

- Compute SHA-256 before parsing.
- Preserve the original filename separately from the generated storage name.
- Never overwrite an original artifact.
- Encrypt storage and backups.
- Prevent direct unauthenticated file access.
- Serve downloads through an authenticated, audited endpoint.
- Scan uploaded files and validate MIME type, size, extension, and PDF structure.
- Do not send files to third-party OCR or AI services unless Ward explicitly enables and understands that data flow.

## Optional waveform format

Store canonical waveforms as Parquet. Provide CSV export for interoperability.

Long format:

```csv
recording_id,time_offset_ms,lead,voltage_mv,quality_flag
0191c5c0-6310-7000-9000-acde48001122,0.000,I,0.031,good
0191c5c0-6310-7000-9000-acde48001122,0.000,II,0.044,good
0191c5c0-6310-7000-9000-acde48001122,3.333,I,0.034,good
```

Required waveform metadata:

- Number of channels
- Ordered lead list
- Sampling frequency
- Sample count per lead
- Voltage unit
- Original numeric representation
- Conversion scale and offset
- Missing-sample policy
- Start timestamp
- Duration
- Parser/converter version
- SHA-256 of original and converted artifacts

Do not store each voltage point as an InfluxDB point. The waveform is an artifact; InfluxDB receives only recording summaries.

## SQLite schema

Create migrations for the following tables.

### `ecg_recordings`

```sql
CREATE TABLE ecg_recordings (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    recorded_at TEXT NOT NULL,
    timezone TEXT NOT NULL,
    duration_seconds REAL NOT NULL,
    device_manufacturer TEXT NOT NULL,
    device_product TEXT NOT NULL,
    lead_configuration TEXT NOT NULL,
    leads_json TEXT NOT NULL,
    average_heart_rate_bpm REAL,
    determination_code TEXT,
    determination_text TEXT,
    signal_quality TEXT,
    recording_reason TEXT NOT NULL,
    symptoms_present INTEGER NOT NULL DEFAULT 0,
    symptoms_json TEXT NOT NULL DEFAULT '[]',
    context_json TEXT NOT NULL DEFAULT '{}',
    clinician_review_json TEXT,
    source_record_id TEXT,
    import_method TEXT NOT NULL,
    confirmed_by_user INTEGER NOT NULL DEFAULT 0,
    confirmed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

Add indexes on `recorded_at`, `determination_code`, `recording_reason`, and `symptoms_present`. If a stable Kardia source-record ID is available, enforce uniqueness scoped to the user.

### `ecg_artifacts`

```sql
CREATE TABLE ecg_artifacts (
    id TEXT PRIMARY KEY,
    recording_id TEXT NOT NULL REFERENCES ecg_recordings(id),
    artifact_type TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    is_original INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(recording_id, sha256)
);
```

### `ecg_parse_attempts`

Record parser version, start/finish time, status, extracted fields, warnings, errors, and confirmation changes. Do not copy the full PDF text into application logs.

## InfluxDB representation

Write one summary point per confirmed recording.

```text
measurement: ecg_recording

tags:
  user=ward
  device=kardiamobile_6l
  lead_configuration=six_lead_limb
  determination=normal_sinus_rhythm
  reason=periodic_baseline
  quality=acceptable

fields:
  average_heart_rate_bpm=68.0
  duration_seconds=30.0
  symptoms_present=false
  symptom_count=0
  clinician_reviewed=false

timestamp:
  recording start time
```

Do not place recording UUIDs, notes, filenames, symptom descriptions, or free text in InfluxDB tags. Keep those in SQLite to prevent sensitive tag exposure and unnecessary cardinality.

When a confirmed record is edited, update SQLite and deterministically rewrite the corresponding InfluxDB summary point. When deleted, tombstone it in SQLite and remove or mark the associated summary point according to a tested deletion procedure; preserve the audit event.

## API endpoints

Add:

```text
POST   /api/v1/ecg/uploads
GET    /api/v1/ecg/uploads/{upload_id}/status
POST   /api/v1/ecg/uploads/{upload_id}/confirm
GET    /api/v1/ecg/recordings
GET    /api/v1/ecg/recordings/{recording_id}
PUT    /api/v1/ecg/recordings/{recording_id}
DELETE /api/v1/ecg/recordings/{recording_id}
GET    /api/v1/ecg/recordings/{recording_id}/artifacts
GET    /api/v1/ecg/artifacts/{artifact_id}/download
POST   /api/v1/ecg/recordings/{recording_id}/clinician-review
GET    /api/v1/ecg/export.csv
GET    /api/v1/ecg/export.json
```

Requirements:

- Authenticated access only
- CSRF protection for browser uploads
- Bounded file sizes
- Idempotency based on SHA-256 and confirmed source identity
- Pagination and date filtering
- Determination, symptom, reason, device, and quality filters
- Audit export and delete operations
- No PDF contents or health details in proxy/application access logs

## PDF parser behavior

Implement the parser as a versioned adapter rather than a single fragile regular expression.

1. Validate the PDF.
2. Extract embedded text when available.
3. Identify report layout/version using stable labels and structural clues.
4. Extract only fields actually present.
5. Retain exact source text for determination and relevant measurements in a private parse record.
6. Assign per-field extraction confidence.
7. Show every extracted value to Ward for confirmation.
8. Never automatically commit a low-confidence date, patient name, determination, or heart rate.
9. Preserve parser warnings.
10. Maintain fixtures made from de-identified or synthetic PDFs for regression tests.

Do not use OCR unless the PDF lacks usable text. If local OCR is used, isolate it and mark all OCR-derived values for confirmation.

## Website requirements

### Upload screen

- Drag-and-drop or file chooser
- Plain-language privacy notice
- Upload progress
- Duplicate detection
- Parse status and warnings
- Original PDF preview when safe

### Confirmation screen

Display editable fields:

- Recording date and time
- Device/product
- Single-lead or six-lead
- Duration
- Heart rate
- Exact Kardia determination
- Signal quality
- Recording reason
- Symptoms and intensity
- Activity and rest before recording
- Notes
- Link to an existing health event or create a new event

The confirmation button must state that Ward is confirming transcription, not endorsing the Kardia determination as a diagnosis.

### Recording detail

- Summary and exact vendor determination
- Original PDF
- Waveform viewer when structured waveform data exists
- Symptom/context panel
- Nearby HealthKit metrics
- Optional clinician review shown separately
- Audit history

### Timeline and trends

- EKG markers on the broader health timeline
- Filter by reason, symptoms, device, quality, and determination
- Counts by determination over selected periods
- Average heart rate trend
- Links to adjacent heart rate, HRV, oxygen saturation, sleep, and activity
- Never imply causation from temporal proximity

## Node-RED and Home Assistant

EKG recordings are periodic. Do not create waveform entities or store raw waveform data in Home Assistant.

Publish only:

```text
sensor.ward_ekg_last_recording
sensor.ward_ekg_last_heart_rate
sensor.ward_ekg_last_determination
sensor.ward_ekg_last_quality
sensor.ward_ekg_recordings_30d
binary_sensor.ward_ekg_last_had_symptoms
```

Include attributes for device, lead configuration, reason, source, recorded timestamp, and review status. Do not include detailed symptom notes or PDF paths in Home Assistant attributes.

Provide an importable Node-RED flow that retrieves the newest confirmed summary from the private API after a commit notification or scheduled poll. It must handle API unavailability, avoid duplicate notifications, and never interpret a Kardia result medically.

Do not create an emergency automation based on Kardia classification. Home Assistant may display the latest result and data freshness but must not decide whether Ward needs medical care.

## Medical-safety language

Display near upload confirmation and recording detail:

> This system stores and organizes personal EKG recordings. It does not diagnose medical conditions, rule out a heart attack, or replace professional medical evaluation. Kardia's automated determination is preserved as vendor-supplied information and may require clinician review.

For symptom capture:

> A normal, unclassified, or unreadable personal EKG does not establish that chest pain, chest pressure, arm discomfort, shortness of breath, fainting, or other concerning symptoms are safe or non-cardiac.

Do not implement AI-generated medical conclusions. If an AI tool summarizes a report, label the output as transcription/organization, cite the source artifact, require Ward's confirmation, and prohibit treatment recommendations.

## Security and privacy

- Keep all endpoints private or strongly authenticated over HTTPS.
- Encrypt artifact storage and backups.
- Use least-privilege service credentials.
- Never expose SQLite, InfluxDB, Node-RED administration, or artifact directories directly.
- Store secrets outside source control.
- Redact health data from logs and error-reporting systems.
- Do not use third-party analytics, session replay, or advertising SDKs.
- Require reauthentication before bulk export or permanent deletion.
- Record administrative access and artifact downloads in a privacy-conscious audit log.
- Provide delete-all-data and export-all-data workflows.

## Testing requirements

### Parser tests

- Single-lead PDF
- Six-lead PDF
- Normal result
- Possible AFib
- Bradycardia
- Tachycardia
- Advanced determination
- Unclassified
- Unreadable
- Missing heart rate
- Missing or ambiguous date
- Scanned PDF requiring local OCR
- Corrupt, encrypted, oversized, or non-PDF upload
- Duplicate upload
- Vendor layout change

### Data tests

- Idempotent confirmation
- Time-zone and daylight-saving handling
- SQLite/InfluxDB partial failure and retry
- Artifact hash verification
- Editing confirmed metadata
- Deleting recording and artifacts
- Clinician review addition without overwriting vendor result
- CSV/JSON export

### Security tests

- Unauthorized upload and download rejected
- Path traversal rejected
- MIME spoofing rejected
- PDF active-content handling
- Sensitive fields absent from logs
- Direct artifact URLs unavailable
- Export/delete require elevated authentication

## Development phases

### Phase 1 — vertical slice

- Manual Kardia PDF upload
- SHA-256 and encrypted preservation
- Basic parser
- Confirmation form
- SQLite recording/artifact records
- One InfluxDB summary point
- Recording detail screen

### Phase 2 — integration

- Health-event linking
- Timeline overlays
- Node-RED flow and Home Assistant summary entities
- CSV/JSON exports
- Backup and restore verification

### Phase 3 — structured waveform support

- Add only after obtaining a legitimate structured export
- Canonical Parquet conversion
- Waveform viewer
- Format and conversion validation
- Original-versus-derived artifact provenance

### Phase 4 — hardening

- Multiple Kardia PDF-layout adapters
- Local OCR fallback
- Clinician review artifacts
- Operational monitoring
- Privacy/security review

## Acceptance criteria

The feature is production-capable when:

1. Ward can upload a Kardia PDF and the untouched original is preserved.
2. Extracted fields are never committed without a confirmation step.
3. Duplicate uploads are recognized by hash and source identity.
4. Single-lead and six-lead records use the same extensible schema.
5. Kardia's exact determination is distinct from normalized codes and clinician interpretations.
6. Symptoms and recording reason can be recorded without being interpreted as diagnoses.
7. SQLite holds detailed metadata and InfluxDB holds only the recording summary.
8. Home Assistant receives only the defined summary entities.
9. PDF and waveform artifacts require authenticated access.
10. Logs contain no PDF contents, waveform data, symptom notes, tokens, or sensitive identifiers.
11. Backup and restore of SQLite, InfluxDB summaries, and encrypted artifacts has been demonstrated.
12. The interface prominently states that Kardia does not check for heart attack and the platform is not a diagnostic system.

## Required implementation outputs

Return:

1. Source code and migrations.
2. Versioned Kardia PDF-parser adapter.
3. Canonical JSON schema and validation code.
4. SQLite migrations and InfluxDB line-protocol examples.
5. Secure artifact-storage implementation.
6. Upload, confirmation, detail, timeline, and export interfaces.
7. Importable Node-RED flow and Home Assistant entity configuration.
8. Automated parser, data, security, and integration tests.
9. Synthetic/de-identified test fixtures.
10. Backup and restore instructions.
11. Privacy and medical-safety review checklist.
12. Known limitations, especially consumer raw-waveform availability.

