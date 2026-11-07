# Runwise: Comprehensive System Design, Architecture Deep Dive & Developer Guide

> **Authoritative System Reference for Developers and AI Agents**
> *Monorepo Integration: Dreeve Homelab Server & Runwise Android Companion App*
> *Target OS: Android 14 (API 34)*
> *Hardware Configuration: Smartwatch (NoiseFit / FIT file sync to Dreeve server) + Smartphone internal sensors (GPS, Accelerometer / Step Detector). **Hardware Invariant: No device possesses a barometric altimeter sensor.***

---

## Table of Contents
1. [Executive Summary & System Purpose](#1-executive-summary--system-purpose)
2. [End-to-End System Design & Architecture](#2-end-to-end-system-design--architecture)
   - [2.1 High-Level Architecture](#21-high-level-architecture)
   - [2.2 Data Ingestion & Synchronization Lifecycle](#22-data-ingestion--synchronization-lifecycle)
   - [2.3 Network Topology (Tailscale Mesh)](#23-network-topology-tailscale-mesh)
3. [Backend Server Deep Dive (`projects/dreeve`)](#3-backend-server-deep-dive-projectsdreeve)
   - [3.1 Domain-Driven Architecture & Clean Separation](#31-domain-driven-architecture--clean-separation)
   - [3.2 Mobile API Endpoints Specification](#32-mobile-api-endpoints-specification)
   - [3.3 Athlete Unified Zones Profile Engine](#33-athlete-unified-zones-profile-engine)
   - [3.4 Database Schema & Cascade Deletion Protocol](#34-database-schema--cascade-deletion-protocol)
   - [3.5 Dynamic GPX & Original File Export Subsystem](#35-dynamic-gpx--original-file-export-subsystem)
4. [Android Companion App Deep Dive (`android/`)](#4-android-companion-app-deep-dive-android)
   - [4.1 Reactive Jetpack Compose UI Architecture](#41-reactive-jetpack-compose-ui-architecture)
   - [4.2 Foreground Tracking Engine & Sensor Lifecycle](#42-foreground-tracking-engine--sensor-lifecycle)
   - [4.3 Smoothed Hysteresis Elevation Algorithm (Zero Barometer)](#43-smoothed-hysteresis-elevation-algorithm-zero-barometer)
   - [4.4 Anti-Chatter Pace Smoothing & Pacing Policies](#44-anti-chatter-pace-smoothing--pacing-policies)
   - [4.5 Periodic Time-Series Stream Sampling & Performance Curves](#45-periodic-time-series-stream-sampling--performance-curves)
   - [4.6 Dual-Layer Voice Coaching & Audio Ducking Subsystem](#46-dual-layer-voice-coaching--audio-ducking-subsystem)
   - [4.7 Interactive Leaflet Map & Offline Native Compose Canvas Fallback](#47-interactive-leaflet-map--offline-native-compose-canvas-fallback)
   - [4.8 Kilometer Splits vs. Laps vs. Interval Step Classification](#48-kilometer-splits-vs-laps-vs-interval-step-classification)
   - [4.9 Two-Tier In-Memory & Persistent Local Storage (Down-Sync Only)](#49-two-tier-in-memory--persistent-local-storage-down-sync-only)
5. [Developer & AI Agent Extensibility Frameworks](#5-developer--ai-agent-extensibility-frameworks)
   - [Framework 1: Adding a New Sport Type](#framework-1-adding-a-new-sport-type)
   - [Framework 2: Adding Bluetooth Low Energy (BLE) Sensors](#framework-2-adding-bluetooth-low-energy-ble-sensors)
   - [Framework 3: Modifying Workout Engine & Audio Cue Triggers](#framework-3-modifying-workout-engine--audio-cue-triggers)
   - [Framework 4: Adding New Map Tile Providers / Satellite Imagery](#framework-4-adding-new-map-tile-providers--satellite-imagery)
   - [Framework 5: Database Migrations & API Schema Extensions](#framework-5-database-migrations--api-schema-extensions)
6. [Complete File-by-File Inventory](#6-complete-file-by-file-inventory)

---

## 1. Executive Summary & System Purpose

**Runwise** is a high-precision, self-hosted running coaching ecosystem tailored for dedicated runners training for specific race targets (e.g., 10K in 1:02:30 @ 6:15 min/km) with strict neuromuscular, biomechanical, and cardiac constraints.

### The Problem It Solves
1. **Commercial App Lock-in & Privacy Risks**: Commercial platforms monetize athlete telemetry, hide training load behind paywalls, and lack tailored biomechanical protocols.
2. **Wearable Limitations**: Wearables record GPS, HR, and cadence internally but cannot broadcast live heart rate telemetry over Bluetooth Low Energy, and lack conversational pacing coach audio cues.
3. **Pacing Drift & Jitter**: Novice and intermediate runners frequently suffer from pacing drift (starting too fast, fading mid-run) and GPS noise overreacting to short-window speed changes.
4. **Hardware Reality (No Barometer)**: Neither the runner's phone nor watch contains a barometric altimeter. Without robust mathematical filtering, raw GPS vertical noise generates absurd elevation gain numbers.

### The Runwise Architecture
- **Self-Hosted Homelab Backend**: A customized, high-performance Dreeve server running on PHP 8.3 / FrankenPHP Caddy with SQLite storage, calculating acute:chronic workload ratios (ACWR), TRIMP training impulse, unified athlete zones, and automated Google Calendar `.ics` sync.
- **Android Companion App**: A native Kotlin + Jetpack Compose app that serves as an intelligent real-time coach. It provides high-frequency GPS tracking, continuous cadence calculation from accelerometer/step sensors, cumulative elevation climb filtering via Smoothed Hysteresis, offline pre-caching, interactive street maps with offline native Canvas fallback, and spoken tactical audio cues.
- **Asymmetric Sync Invariant (Strictly Down-Sync)**: Smartwatches sync directly to the server via FIT files. Phone recordings remain stored locally on disk and are **NEVER uploaded to the server via HTTP POST**, preserving server database purity.

---

## 2. End-to-End System Design & Architecture

### 2.1 High-Level Architecture

```mermaid
flowchart TD
    subgraph Athlete["Athlete Environment"]
        Watch["Wearable Device\n(ColorFit Pro 4)\n- Internal GPS/HR/Cadence\n- Post-run FIT/GPX export"]
        Sensors["Android Internal Sensors\n- GPS Provider (1Hz, 1m delta)\n- Accelerometer / Step Counter\n- (NO Barometer Sensor)"]
        Earphones["Bluetooth Earphones / Audio\n- Spoken 500m checkpoints\n- Spoken 1km milestone recaps\n- Pace tolerance alerts (±25s)\n- Low-cadence warnings (<162 SPM)"]
    end

    subgraph MobileApp["Runwise Android Companion App (Kotlin + Compose)"]
        PreStart["Pre-Start Engine\n- GPS satellite acquisition\n- Locked fix threshold (≤20m)\n- Audio ready chime"]
        Tracking["TrackingService (Foreground Service)\n- Partial WakeLock\n- 25-30s rolling EMA pace window\n- Smoothed Hysteresis Elevation Filter\n- 5s periodic stream sampling"]
        Audio["AudioCoach Engine\n- TTS Ducking\n- 45s alert cooldown\n- 3-strike out-of-boundary confirmation\n- Dual-layer cue hierarchy"]
        MapEngine["Leaflet Map & Canvas Fallback\n- CartoDB Dark Matter WebView\n- Native Compose Canvas Polyline Fallback"]
        Repo["RunwiseRepository\n- 2-Tier Cache (RAM + Disk JSON)\n- Persistent local_activities.json\n- Background prefetch (top 15)\n- Down-sync only from server"]
    end

    subgraph Network["Network Layer"]
        Tailscale["Tailscale WireGuard Mesh Network\n(Encrypted IPv4: 100.66.54.63:8081)"]
    end

    subgraph Backend["Homelab Server: Dreeve Fork (PHP 8.3 / FrankenPHP)"]
        MobileSync["MobileSyncRequestHandler\n- Delta sync (?since=)\n- Cascade deletion\n- GPX on-the-fly generator\n- Original file downloader"]
        Analytics["RunningAnalyticsService\n- Unified Daniels/Friel 5-zone pace\n- Fox formula relative HR zones\n- ACWR & TRIMP load calculations"]
        PlanEngine["RunPlanService\n- 10K date-wise training plan\n- Daily calf exercise prescription\n- .ics Google Calendar export"]
        DB[(SQLite Database\n/var/www/storage/database/dreeve.db\n12 Interconnected Tables)]
    end

    Watch -.->|Post-workout file import| Backend
    Sensors -->|Raw Sensor Events| Tracking
    Tracking -->|Speech Requests| Audio
    Audio -->|Audio Stream| Earphones
    PreStart -->|Confirm Start| Tracking
    Tracking -->|GPS Coordinates| MapEngine
    Tracking -->|Persist Local Activity & Streams| Repo
    Repo <==>|Encrypted REST API (Down-Sync Only)| Tailscale
    Tailscale <==>|Port 8081| Backend
    Backend <-->|Dbal Query / Insert| DB
```

### 2.2 Data Ingestion & Synchronization Lifecycle

```mermaid
sequenceDiagram
    autonumber
    actor User as Athlete
    participant UI as ActiveRunScreen / PreStart
    participant TS as TrackingService
    participant AC as AudioCoach
    participant Repo as RunwiseRepository
    participant Server as Dreeve Server

    User->>UI: Taps "Run", "Walk", or "Hike"
    UI->>TS: ACTION_PREPARE (sportType, workout)
    TS->>TS: Start LocationManager GPS updates
    TS-->>UI: State: GpsStatus.SEARCHING (Amber banner)
    loop Satellite Acquisition
        TS->>TS: OnLocationChanged(acc)
        alt Accuracy <= 20 meters
            TS-->>UI: State: GpsStatus.LOCKED (Green banner)
            TS->>AC: "GPS acquired. Ready to start."
            AC->>User: Spoken voice cue
        end
    end

    User->>UI: Taps "START RUN"
    UI->>TS: ACTION_CONFIRM_START
    TS->>TS: Start timer loop, start CadenceTracker, start 5s stream sampler
    TS->>AC: Announce Step 1 target pace & distance
    AC->>User: Spoken workout introduction

    loop Active Workout Tracking
        TS->>TS: Filter GPS fixes (accuracy <= 25m, speed <= 35m/s)
        TS->>TS: Apply Smoothed Hysteresis Elevation Filter (no barometer)
        TS->>TS: Compute 25-30s rolling EMA pace
        opt Every 5 Seconds
            TS->>TS: Sample pace, cadence, elevation, distance into streams
        end
        opt Running Sport Type & Target Pace Active
            TS->>AC: evaluatePaceWarning(rolling, target, ±25s, 3-strike check)
            TS->>AC: evaluateCadenceWarning(spm, 162)
        end
        opt Every 500m Checkpoint
            TS->>AC: announceCheckpoint(500m, lapPace)
            AC->>User: Spoken checkpoint ("500 meters. Lap pace X:XX")
        end
        opt Every 1,000m Milestone
            TS->>AC: announceKilometerMilestone(kmNumber, splitPace, totalTime, avgPace)
            AC->>User: Spoken full milestone recap
        end
        TS-->>UI: Live update: Pace, Time, Cadence, Active Splits Bar Chart, Map
    end

    User->>UI: Taps "FINISH"
    UI->>UI: Prompts "Save Activity" dialog with pre-filled name
    User->>UI: Edits or confirms custom name and taps "Save"
    UI->>TS: ACTION_STOP (EXTRA_CUSTOM_NAME)
    TS->>Repo: savePhoneRecordedWorkout(sport, workoutType, distance, time, streams, splits, laps, customName)
    Repo->>Repo: Write local_activities.json & activity_detail_<id>.json (with full streams)
    Repo->>Repo: Generate standard GPX to /sdcard/Download/Runwise/
    TS-->>UI: Workout saved locally; feed displays updated activity without server upload
```

### 2.3 Network Topology (Tailscale Mesh)
- **Local Host**: `http://127.0.0.1:8081` (inside Docker / homelab host).
- **Tailscale Mesh Endpoint**: `http://100.66.54.63:8081` (wire-speed encrypted connection accessible over cellular network worldwide without open ports).
- **Client Discipline**: The mobile app performs strictly `GET` requests against the API for telemetry, sync, and detail views. No phone workout `POST` requests are dispatched.

---

## 3. Backend Server Deep Dive (`projects/dreeve`)

### 3.1 Domain-Driven Architecture & Clean Separation
The backend operates under Symfony / FrankenPHP Caddy with pure domain separation:
- `Application/`: Use cases, commands, message handlers.
- `Controller/Api/Mobile/`: `MobileSyncRequestHandler` serving REST payloads.
- `Domain/Activity/`: Aggregates for activities, downsampled streams, kilometer splits, and structured interval steps.
- `Domain/Athlete/`: Unified pace and HR zones calculator implementing Jack Daniels VDOT and Joe Friel 5-zone models.
- `Infrastructure/`: DBAL connections, SQLite persistence, Leaflet config.

### 3.2 Mobile API Endpoints Specification

| Method | Route | Description | Sync Direction |
|---|---|---|---|
| `GET` | `/api/mobile/health` | Healthcheck & server UTC time | Down-Sync |
| `GET` | `/api/mobile/sync` | Full/delta sync (profile, readiness, plan, 90-day history, activities) | Down-Sync |
| `GET` | `/api/mobile/activities/{id}` | Detailed activity payload with downsampled curves, intervals, and zones | Down-Sync |
| `DELETE`| `/api/mobile/activities/{id}` | Cascading deletion across 12 database tables | Server Management |
| `GET` | `/api/mobile/activities/{id}/gpx` | On-the-fly GPX synthesis from streams / polyline | Export |
| `GET` | `/api/mobile/activities/{id}/original` | Original FIT or GPX file download | Export |

---

## 4. Android Companion App Deep Dive (`android/`)

### 4.1 Reactive Jetpack Compose UI Architecture
The companion app is built entirely with Jetpack Compose, unidirectional data flow (UDF), and Kotlin Coroutines/StateFlow. It comprises:
- `MainActivity.kt`: Central coordinator handling Android 14 permissions and HUD navigation override.
- `TrackingService.kt`: Foreground service maintaining location, cadence, elevation, stream sampling, and audio coaching.
- `ActiveRunScreen.kt`: 3-page swipeable HUD (`HorizontalPager`: HUD, Map, Laps/Splits) with Strava 3-dot pager indicator `[ • • • ]`.
- `ActivityDetailScreen.kt`: Deep analytics dashboard featuring multi-metric performance curves, true kilometer splits, lap analysis, and offline map fallback.
- `AudioCoach.kt`: Tactical voice coach with TTS ducking and anti-chatter filters.
- `RunwiseRepository.kt`: Persistent local storage, memory cache, and strictly down-sync network client.

---

### 4.2 Foreground Tracking Engine & Sensor Lifecycle
- Runs with `ServiceCompat.startForeground` specifying `FOREGROUND_SERVICE_TYPE_LOCATION`.
- Acquires `PowerManager.PARTIAL_WAKE_LOCK` guaranteeing CPU clock execution while the device display is locked in a pocket or armband.
- **GPS Ingestion & Filtering:**
  - Registers `LocationManager.GPS_PROVIDER` with `minTimeMs = 1000L`, `minDistanceM = 1.0f`.
  - Fixes with `accuracy > 25.0f` are discarded.
  - Fixes implying velocity $> 35.0\text{ m/s}$ ($126\text{ km/h}$) are discarded.
  - Geodesic distance is computed via WGS84 ellipsoid formula (`lastLocation.distanceTo(currentLocation)`).

---

### 4.3 Smoothed Hysteresis Elevation Algorithm (Zero Barometer)
**Hardware Context:** Neither phone nor watch has a barometric sensor. GPS altitude data contains high multipath noise and vertical jitter ($\pm 3$ to $8\text{m}$).

**The 3-Stage Mathematical Filter:**
1. **Multipath Vertical Spike Guard:**
   Rejects any GPS fix where the raw altitude jumps unrealistically relative to the smoothed reference:
   $$\text{If } |h_{\text{raw}} - h_{\text{smoothed}}| \ge 40.0\text{ m} \implies \text{Fix discarded for elevation math}$$
2. **Low-Pass Exponential Moving Average (EMA):**
   Smooths out normal GPS vertical noise with smoothing parameter $\alpha = 0.15$ (averaging over ~15 seconds of GPS fixes):
   $$h_{\text{smoothed}, t} = h_{\text{smoothed}, t-1} + 0.15 \times (h_{\text{raw}, t} - h_{\text{smoothed}, t-1})$$
3. **2.0-Meter Climbing Hysteresis Barrier:**
   Accumulates elevation gain only when verified climbing exceeds the hysteresis barrier:
   $$\Delta \text{Gain} = h_{\text{smoothed}} - h_{\text{ref}}$$
   - **Climb Condition:** If $\Delta \text{Gain} \ge +2.0\text{ m}$, add $\Delta \text{Gain}$ to `cumulativeElevationGainMeters` and advance $h_{\text{ref}} = h_{\text{smoothed}}$.
   - **Descent Condition:** If $h_{\text{ref}} - h_{\text{smoothed}} \ge +2.0\text{ m}$, reset $h_{\text{ref}} = h_{\text{smoothed}}$ (without reducing gain).
   - **Flat Ground Protection:** On flat ground, GPS altitude fluctuations remain within $[-1.5\text{m}, +1.5\text{m}]$. The 2.0m climbing barrier is never crossed, guaranteeing **0.0m phantom gain**.

---

### 4.4 Anti-Chatter Pace Smoothing & Pacing Policies
- **Problem with Short Pace Windows:** A 10-second window on phone GPS generates extreme pace volatility ($\pm 1:00$ to $1:30$/km variation). Evaluating strict $\pm 10$s tolerance triggers non-stop voice warnings ("Too fast! / Too slow!"), causing severe runner frustration.
- **Runwise Anti-Chatter Rules:**
  1. **25–30 Second Rolling EMA Window:** Pacing is computed over a minimum 25-second rolling buffer (requiring distance $\Delta d \ge 50\text{m}$), absorbing turn artifacts and arm swings.
  2. **Tolerance Calibration:**
     - Open / Base Runs: Pacing tolerance is $\pm 25$–$30$ seconds (or disabled unless the runner sets a target pace).
     - Structured Pace Lock Steps: Tolerance is $\pm 15$–$20$ seconds.
  3. **3-Strike Confirmation Rule:** A pace alert is triggered ONLY after **3 consecutive** out-of-boundary evaluations spaced 1 second apart.
  4. **Refractory Cooldown:** Minimum 45-second delay between consecutive voice alerts.
  5. **Startup Grace Period:** All pace warnings are muted for the first 200m or 60 seconds of a workout or step.

---

### 4.5 Periodic Time-Series Stream Sampling & Performance Curves
To power the multi-metric performance curves on the activity detail screen:
1. **Foreground Stream Sampling Loop:**
   - Every 5,000ms while active and unpaused, `TrackingService` records:
     - `timeOffsetSeconds`: Current moving time.
     - `distanceMeters`: Cumulative distance.
     - `paceSecPerKm`: Current smoothed rolling pace.
     - `cadenceSpm`: Current cadence from `CadenceTracker`.
     - `elevationMeters`: Smoothed altitude $h_{\text{smoothed}}$.
2. **Persistence:**
   - Saved inside `activity_detail_<id>.json` under `"streams"`:
     ```json
     "streams": {
       "paceCurve": [390, 385, 372, 368, ...],
       "cadenceCurve": [158, 162, 164, 165, ...],
       "elevationCurve": [12.4, 12.8, 13.5, 15.2, ...],
       "distanceCurve": [15, 38, 62, 88, ...]
     }
     ```
3. **Visualization in `ActivityDetailScreen.kt` (`PerformanceCurvesSection`):**
   - Renders smooth bezier curves using native Jetpack Compose `Canvas`.
   - Displays min, max, and average reference lines.
   - Interactive metric tabs: **Pace**, **Cadence**, and **Elevation Profile**.

---

### 4.6 Dual-Layer Voice Coaching & Audio Ducking Subsystem
- **Audio Ducking:** All spoken audio cues request `AudioManager` focus with `AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK` and `USAGE_ASSISTANCE_NAVIGATION_GUIDANCE`. Background music (Spotify, Podcasts) dips smoothly to 20% volume and restores immediately upon speech completion.
- **Hierarchical Cue Sequence:**
  1. **500m Intermediate Checkpoint:**
     - Triggered every 500m:
     - Spoken text: *"500 meters. Lap pace, [M:SS]."*
     - Concise, non-intrusive progress marker.
  2. **1,000m Full Kilometer Milestone:**
     - Triggered at exact 1.0 km, 2.0 km, 3.0 km boundaries:
     - Spoken text: *"Kilometer [N] complete. Split pace, [M:SS]. Total time, [H:MM:SS]. Average pace, [M:SS]."*
     - Comprehensive milestone recap; resets the intermediate 500m sub-counter.
  3. **Structured Step Transition:**
     - Triggered on step distance/duration completion:
     - Spoken text: *"Step [X] of [Y]: [Title]. Target [Distance/Duration]. Target pace [M:SS]."*

---

### 4.7 Interactive Leaflet Map & Offline Native Compose Canvas Fallback
- **Leaflet WebView (`LeafletMapView.kt`):**
  - Android API 29–34 WebView requires explicit universal access permissions to load CartoDB tiles from `android_asset/leaflet_map.html`:
    ```kotlin
    settings.apply {
        allowFileAccess = true
        allowContentAccess = true
        allowUniversalAccessFromFileURLs = true
        allowFileAccessFromFileURLs = true
        mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        domStorageEnabled = true
        javaScriptEnabled = true
    }
    ```
- **Offline Compose Canvas Polyline Fallback:**
  - If the device is offline, has airplane mode active, or tile fetching fails, the component automatically falls back to an internal high-performance Jetpack Compose `Canvas`:
    - Projects GPS coordinate points onto the canvas bounding box.
    - Renders the full route track in high-contrast orange (`#F26822`).
    - Draws pulsing green start pin and checkered finish flag.
    - Guarantees the route track is **always rendered with zero blank screens**.

---

### 4.8 Kilometer Splits vs. Laps vs. Interval Step Classification
To prevent misleading analysis metrics:
1. **Kilometer Splits (`KilometerSplitsSection`):**
   - Always aggregated into true 1,000-meter metric chunks (plus fractional final split).
   - A 2.55 km run generates **exactly 3 rows**:
     - Split 1: `1.00 km`
     - Split 2: `1.00 km`
     - Split 3: `0.55 km` (partial remainder)
   - 500m sub-splits must never be mapped as `1 km`, `2 km`, `3 km`.
2. **Workout Laps Analysis (`WorkoutLapsAnalysisSection`):**
   - For structured interval workouts: displays semantic badges (`WARMUP`, `INTERVAL`, `RECOVERY`, `COOLDOWN`).
   - For free / base runs: displays standard lap rows without false "INTERVAL" badges (`isInterval = false`).

---

### 4.9 Two-Tier In-Memory & Persistent Local Storage (Down-Sync Only)
- **Local Files:**
  - `filesDir/local_activities.json`: Array of local `ActivityItem` instances for the feed.
  - `cacheDir/activity_detail_<id>.json`: Complete telemetry payload (coordinates, streams, splits, laps).
  - Memory Cache: `ConcurrentHashMap<String, ActivityDetailResponse>` ensures instant UI transitions.
  - Dual GPX Generation: Saves to `context.getExternalFilesDir(null)/Runwise/<id>.gpx` and `/sdcard/Download/Runwise/<SafeName>.gpx`.
- **Zero Server Upload Invariant:** Phone recordings are completely self-contained. The server database receives FIT files exclusively from the smartwatch bridge.

---

## 5. Developer & AI Agent Extensibility Frameworks

Refer to the monorepo for detailed guides:
- **Framework 1: Adding a New Sport Type** (Enum, database columns, UI colors, alert filtering).
- **Framework 2: Adding Bluetooth Low Energy (BLE) Sensors** (Heart Rate GATT `0x180D`, RSC `0x1814`).
- **Framework 3: Modifying Workout Engine & Audio Cue Triggers** (Spoken pacing tolerances and audio metronomes).
- **Framework 4: Adding New Map Tile Providers / Satellite Imagery** (CartoDB Voyager, OpenTopoMap, ESRI Satellite).
- **Framework 5: Database Migrations & API Schema Extensions** (Doctrine migrations and Moshi DTO updates).

---

## 6. Complete File-by-File Inventory

The Runwise codebase is organized as follows:
- **Backend**: `projects/dreeve/src/`
- **Android App**: `projects/runwise-android/app/`
- **Documentation**:
  - `RUNWISE_ANDROID_COMPLETE_FUNCTIONAL_SPECIFICATION.md`
  - `RUNWISE_SYSTEM_DESIGN_AND_DEVELOPER_GUIDE.md`

---

## 7. Future Activities & Post-Race Roadmap

### Feature Activity 1: Jack Daniels VDOT & Dynamic Training Pace Radar
> **Status:** Queued for Post-Target Race Phase (Active plan locked for Nov 22, 2026 10K Race).

- **Objective:** Dynamically calculate the runner's physiological **VDOT score** (Jack Daniels Running Formula) from competitive best efforts across 3K, 5K, and 10K in the database, without forcing the athlete into rigid laboratory testing.
- **Components to Implement:**
  1. **VDOT Calculator Engine (`src/Domain/Activity/Running/VdotCalculator.php`):**
     $$\text{VO}_2 = \frac{-4.60 + 0.182258 \cdot v + 0.000104 \cdot v^2}{0.8 + 0.1894393 \cdot e^{-0.012778 \cdot t} + 0.2989558 \cdot e^{-0.1932605 \cdot t}}$$
     Where $v$ is velocity (m/min) and $t$ is time (minutes).
  2. **Dynamic 5-Pace Prescription Matrix:**
     - **E-Pace (Easy Aerobic):** 59–74% VO2max (rebuilding base & tendon remodeling).
     - **M-Pace (Marathon Tempo):** 75–84% VO2max (aerobic capacity under moderate glycogen burn).
     - **T-Pace (Lactate Threshold):** 83–88% VO2max (exact 60-minute maximum sustainable pace).
     - **I-Pace (VO2max Intervals):** 95–100% VO2max (cardiac output and stroke volume development).
     - **R-Pace (Repetition Speed):** Neuromuscular turnover and running economy.
  3. **UI Integration:**
     - Dedicated page `/vdot` with interactive pace sliders and race equivalence radar chart.
     - Mobile sync export in `athlete.vdot` and `athlete.trainingPaces`.

---

## 8. Eddington Replacement: Longitudinal Aerobic Efficiency (EF & Cardiac Drift Tracker)

> **Context:** Cycling-derived Eddington numbers ($E$ days of $\ge E$ km) are counterproductive for running due to biomechanical impact thresholds. Replaced with longitudinal aerobic cardiovascular fitness tracking.

- **Core Metric 1: Efficiency Factor (EF):**
  $$\text{EF} = \frac{\text{Average Speed (meters / minute)}}{\text{Average Heart Rate (bpm)}}$$
  Calculated across steady-state runs. An upward trend over trailing 30, 60, and 90 days indicates greater aerobic power at identical or lower cardiovascular stress.
- **Core Metric 2: Decoupling ($Pw:Hr$ / Cardiac Drift) Longitudinal History:**
  Tracks heart rate drift between the 1st half and 2nd half of long runs. Sustained decoupling under 5% confirms deep aerobic endurance base.
- **Core Metric 3: Heart Rate Cost per Kilometer:**
  $$\text{Cardiac Cost} = \text{Average HR} \times \text{Pace (minutes / km)}$$
  Normalizes beats required per kilometer traveled regardless of temperature or minor terrain changes.
