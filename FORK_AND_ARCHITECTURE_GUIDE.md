# Dreeve Forking Guide & Architecture Reference: Advanced Running Analytics

This document provides:
1. **The Step-by-Step Path to Forking**: How to convert your local modified Dreeve installation into your own clean, maintainable Git repository/fork on GitHub without losing upstream sync capabilities.
2. **System Design & Architecture**: How Dreeve operates internally and how our extensions adhere to Clean Architecture / Domain-Driven Design (DDD).
3. **Exhaustive File-by-File Inventory**: Every single modification, addition, and configuration change made to stock Dreeve.

---

## Part 1: Step-by-Step Path to Creating Your Own Fork

You do not need to start over or re-clone to create your own fork. Your local Dreeve directory is already a Git repository pointing to `https://github.com/dreeveapp/dreeve.git`. Follow these steps to publish it to your personal GitHub account.

```
       Upstream Repository                        Your Personal GitHub
 (github.com/dreeveapp/dreeve)                 (github.com/<you>/dreeve)
             ▲                                            ▲
             │ (fetch updates)                            │ (push your changes)
             │                                            │
             └─────────────── Local Machine ──────────────┘
                         (local working copy)
```

### Step 1: Pre-flight Safety & Privacy Check

Before staging files, verify that no personal database files, API secrets, or local cache files will be committed.

1. **Check `.gitignore`**:
   Dreeve's `.gitignore` already ignores:
   - `/storage` and `*.db*` (your SQLite database `storage/database/dreeve.db` will **not** be committed).
   - `/.env.local`, `/.env.*.local` (local secrets).
   - `/var/` (Symfony cache & logs).
2. **Environment Configuration**:
   - Copy `.env.example` to `.env.local`: `cp .env.example .env.local`
   - Fill in your `APP_SECRET`, admin credentials, and optional AI / Strava keys. `.env.local` is strictly gitignored and will never be committed.
3. **Decide on Helper Files**:
   - `docker-compose.override.yml`: Useful to commit if you want host code mounts enabled by default on clone.
   - `bin/phpunit.phar`: A 5.6 MB binary test runner. You can either commit it for standalone offline testing or add it to `.gitignore` if you prefer keeping the repo small.
   - `AI_AGENT_GUIDE.md`: Useful documentation for AI agent configurations.

### Step 2: Create a New Empty Repository on GitHub

1. Go to [GitHub.com](https://github.com/new).
2. Name the repository (e.g., `dreeve` or `dreeve-running`).
3. Set the visibility (**Public** or **Private**).
4. **Important**: Do **not** check "Add a README file", "Add .gitignore", or "Choose a license". Leave it completely empty.

### Step 3: Configure Git Remotes Locally

Open your terminal in your repository root and run:

```bash
cd /path/to/dreeve

# 1. Rename existing 'origin' (upstream Dreeve) to 'upstream'
git remote rename origin upstream

# 2. Add your new GitHub repository as 'origin'
# (Replace YOUR_USERNAME with your actual GitHub username)
git remote add origin git@github.com:YOUR_USERNAME/dreeve.git
# Or if using HTTPS:
# git remote add origin https://github.com/YOUR_USERNAME/dreeve.git

# 3. Verify your remotes
git remote -v
```
You should see:
```text
origin    git@github.com:YOUR_USERNAME/dreeve.git (fetch)
origin    git@github.com:YOUR_USERNAME/dreeve.git (push)
upstream  https://github.com/dreeveapp/dreeve.git (fetch)
upstream  https://github.com/dreeveapp/dreeve.git (push)
```

### Step 4: Stage and Commit the Modifications

To keep your Git history clean and professional, commit the changes in logical, atomic stages:

#### Commit 1: Core Running Domain & Analytics Engine
```bash
git add src/Domain/Athlete/PaceZone/
git add src/Domain/Activity/Running/
git add tests/Domain/Athlete/PaceZone/
git add tests/Domain/Activity/Running/
git commit -m "feat(running): implement advanced running analytics engine and pace zones

- Add 5-zone physiological pace system anchored to Jack Daniels / Joe Friel threshold pace
- Add time-weighted PaceZoneCalculator with pause exclusion and GPS flutter smoothing
- Add AerobicDecouplingCalculator with strict steady-state eligibility checks
- Add RaceTimePredictor using Pete Riegel power law with recency weighting
- Add RunningEffortScore relative cardiovascular scoring and recovery window
- Add RunningTrainingStatusAdvisor for plain-language fitness/freshness coaching
- Add RunningStreamValidator for GPS and heart-rate telemetry integrity checks
- Add full PHPUnit test suite (24 unit tests)"
```

#### Commit 2: Activity UI & Controller Integration
```bash
git add src/Controller/Page/Activity/ActivityRequestHandler.php
git add templates/html/activity/activity--sport-type--run.html.twig
git commit -m "feat(ui): add running analytics cards and summary badges to activity view

- Inject RunningAnalyticsService into ActivityRequestHandler
- Render Pace Zones distribution chart with percentage and distance metrics
- Render Aerobic Decoupling & HR Drift card with Friel evaluation badges
- Render Session Relative Effort and recovery window recommendation
- Render Stream Data Integrity telemetry audit card"
```

#### Commit 3: AI Workout Coach ("Mark") Tools
```bash
git add src/Domain/Integration/AI/NeuronAIAgent.php
git add src/Domain/Integration/AI/Tool/
git add AI_AGENT_GUIDE.md
git commit -m "feat(ai): expand Mark running coach persona with 6 dedicated analytics tools

- Add get_all_runs with full telemetry summaries
- Add get_running_records for PR tracking (400m to Marathon)
- Add get_running_trends for weekly/monthly volume and pace progression
- Add get_race_predictions for Riegel race estimations
- Add get_running_analytics for session decoupling and training readiness
- Update NeuronAIAgent system prompt to act as an exercise physiologist"
```

#### Commit 4: Container Configuration, Tooling, & Documentation
```bash
git add docker-compose.yml
git add docker-compose.override.yml
git add bin/phpunit.phar
git add FORK_AND_ARCHITECTURE_GUIDE.md
git add RUNWISE_SYSTEM_DESIGN_AND_DEVELOPER_GUIDE.md
git commit -m "chore(infra): configure timezone, host mounts, and standalone PHPUnit runner

- Set timezone to Asia/Kolkata across services
- Mount host ./src, ./templates, and ./tests into Docker container for live development
- Add standalone PHPUnit 11 executable in bin/phpunit.phar
- Add comprehensive architecture, system design, and fork guides"
```

#### Commit 5: Runwise Mobile REST API, Training Planner & UI Rebranding
```bash
git add src/Application/AppName.php
git add src/Application/Navigation/NavigationSection.php
git add templates/html/navigation/sidebar.html.twig
git add src/Controller/Api/Mobile/
git add src/Controller/Page/RunPlanRequestHandler.php
git add templates/html/run-plan.html.twig
git commit -m "feat(mobile): add Runwise REST API endpoints, training planner, and UI branding

- Brand UI application title to 'Runwise' via AppName
- Add mobile delta synchronization endpoints under /api/mobile/
- Implement date-wise training plan scheduler and .ics calendar exporter
- Add mobile navigation items to sidebar"
```

### Step 5: Push to Your Fork

```bash
# Push your master branch and set upstream tracking to your new origin
git push -u origin master
```

### Step 6: How to Sync with Upstream Dreeve in the Future

Whenever the original creator of Dreeve updates the software:
```bash
# Fetch changes from the official repository
git fetch upstream

# Merge upstream changes into your master branch
git merge upstream/master

# Resolve any merge conflicts (if upstream touched ActivityRequestHandler or run template), then push:
git push origin master
```

---

## Part 2: System Architecture & Design Guide

### Architectural Philosophy
Dreeve is built with **Symfony 7**, **PHP 8.4/8.5**, and **FrankenPHP**. It does not use Doctrine ORM for activity streaming; instead, it uses **Doctrine DBAL** with direct SQL queries on SQLite, storing telemetry streams as JSON arrays.

When adding Garmin-grade running analytics, we strictly preserved **Hexagonal / Clean Architecture**:
1. **Zero Framework Coupling in Core Domain**: All running algorithms (`PaceZoneCalculator`, `AerobicDecouplingCalculator`, `RaceTimePredictor`, `RunningEffortScore`) are pure PHP classes. They have zero dependencies on Symfony, Doctrine, or HTTP classes. They take scalar arrays and numbers, making them 100% unit-testable and reusable.
2. **Orchestration Service**: `RunningAnalyticsService` acts as the domain facade. It queries SQLite for database facts (best efforts, training metrics), pulls stream arrays, executes the domain algorithms, and returns typed results.
3. **Thin Presentation Layer**: `ActivityRequestHandler` calls the service and passes the resulting objects into Twig. The Twig template only renders the domain models.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                              │
│  ActivityRequestHandler.php  │  activity--sport-type--run.html.twig    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Calls
┌───────────────────────────────────▼────────────────────────────────────┐
│                       APPLICATION SERVICE                              │
│                   RunningAnalyticsService.php                          │
└───────────┬───────────────────────┬────────────────────────────┬───────┘
            │ Queries               │ Reads                      │ Uses
┌───────────▼───────────┐ ┌─────────▼───────────┐ ┌──────────────▼───────┐
│     SQLITE DB         │ │  ACTIVITY STREAMS   │ │    PURE DOMAIN       │
│  - ActivityBestEffort │ │  - velocity_smooth  │ │  PaceZoneCalculator  │
│  - TrainingMetrics    │ │  - heartrate        │ │  DecouplingCalculator│
│  - Activity (HR/dist) │ │  - moving / time    │ │  RaceTimePredictor   │
└───────────────────────┘ └─────────────────────┘ └──────────────────────┘
```

---

## Part 3: Detailed Breakdown of Implemented Features

### Feature A: Running Pace Zones
* **Objective**: Replace arbitrary static zones with athlete-specific physiological thresholds.
* **Anchor Detection (`autoDetectThresholdPace`)**:
  - Queries `ActivityBestEffort` for the athlete's fastest 5K over the past 90–180 days.
  - Automatically identifies fastest 5K pace ($372\text{ s/km}$ from *"Vector Registors"*).
  - Calculates Functional Threshold Pace ($FTPace = 5\text{k Pace} \times 1.05 = 391\text{ s/km} \approx 6:31\text{ /km}$).
* **Physiological Boundaries**:
  - Zone 1 (Recovery): $> 129\%$ ($> 8:24\text{ /km}$)
  - Zone 2 (Aerobic Base): $114\% - 129\%$ ($7:26 - 8:24\text{ /km}$)
  - Zone 3 (Tempo): $106\% - 113\%$ ($6:54 - 7:26\text{ /km}$)
  - Zone 4 (Threshold): $98\% - 105\%$ ($6:23 - 6:54\text{ /km}$)
  - Zone 5 (Anaerobic): $< 98\%$ ($< 6:23\text{ /km}$)
* **Time-Weighted Math (`PaceZoneCalculator`)**:
  - Resamples unequal intervals ($\Delta t_i = t_{i+1} - t_i$).
  - Excludes pauses where speed $< 0.5\text{ m/s}$ or `moving == false`.
  - Caps unnatural time gaps ($> 60\text{s}$) to avoid false accumulation during pauses.
  - Applies a 3-point moving average filter to velocity samples to eliminate 1-second GPS multipath flutter from wrist watches (like Noise ColorFit Pro 4).

### Feature B: Aerobic Decoupling & Heart-Rate Drift
* **Objective**: Quantify aerobic endurance by measuring cardiac drift relative to running speed.
* **Formula**:
  - First-half Efficiency Factor: $EF_1 = \frac{\text{Average Speed}_1\text{ (m/s)}}{\text{Average HR}_1\text{ (bpm)}}$
  - Second-half Efficiency Factor: $EF_2 = \frac{\text{Average Speed}_2\text{ (m/s)}}{\text{Average HR}_2\text{ (bpm)}}$
  - Decoupling Percentage: $Pw:Hr = \left(\frac{EF_1 - EF_2}{EF_1}\right) \times 100\%$
* **Strict Eligibility Filtering**:
  - Trims first 5–10 minutes as warm-up.
  - Excludes workouts with pace coefficient of variation $CV \ge 22\%$ (rejects interval workouts / fartleks).
  - Excludes runs with excessive elevation change ($> 3\%$ grade change).
  - Requires at least 20 minutes of valid moving duration and $\ge 75\%$ HR sensor coverage.
* **Coaching Evaluation**: Joe Friel benchmark ($< 5\%$ indicates strong aerobic base; $5 - 10\%$ moderate drift; $> 10\%$ significant fatigue or insufficient aerobic conditioning).

### Feature C: Race Time Prediction
* **Objective**: Predict 5K, 10K, Half Marathon, and Marathon finish times based on actual performances.
* **Model**: Pete Riegel power law:
  $$T_2 = T_1 \times \left(\frac{D_2}{D_1}\right)^{1.06}$$
* **Candidate Scoring**:
  - Evaluates recent best efforts and activities.
  - Scores candidates using recency weighting ($\text{weight} \propto e^{-\lambda \cdot \text{days}}$) and distance similarity ($\frac{\min(D_1, D_2)}{\max(D_1, D_2)}$).
  - Provides realistic uncertainty bounds ($\pm 3\%$ for near distances up to $\pm 10\%$ for marathon extrapolations) to prevent false precision.

### Feature D: Session Relative Effort & Recovery Window
* **Objective**: Provide an intuitive 0–120+ cardiovascular session score based on heart rate distribution.
* **Weighting**: Time in HR zones weighted exponentially ($w_1 = 1, w_2 = 2, w_3 = 4, w_4 = 7, w_5 = 11$).
* **Recovery Modeling**: Recommends recovery duration between 12 and 72 hours based strictly on recorded cardiovascular load, explicitly noting that sleep and nocturnal HRV are not fabricated.

### Feature E/F/G: Training Status & Recovery Advisor
* **Objective**: Synthesize Banister impulse-response model metrics into plain-language coaching states.
* **Input Metrics**: Chronic Training Load (CTL, 42-day fitness), Acute Training Load (ATL, 7-day fatigue), Training Stress Balance (TSB = CTL - ATL, freshness), and Acute-to-Chronic Workload Ratio (ACWR).
* **States**: Peaking, Productive, Maintaining, Overreaching, Detraining, Recovery.

### Feature H: Telemetry & Stream Validation
* **Objective**: Detect GPS multipath errors, sudden unrealistic velocity spikes ($> 43.2\text{ km/h}$), dropped packets, and missing heart rate samples. Scores stream integrity from 0 to 100.

---

## Part 4: Complete Inventory of Files

### 1. Modified Existing Files

| File | Changes Made |
| :--- | :--- |
| `docker-compose.yml` | Updated container timezone from `Europe/Brussels` to `Asia/Kolkata`. |
| `src/Controller/Page/Activity/ActivityRequestHandler.php` | Injected `RunningAnalyticsService` into constructor; passed `paceZones`, `aerobicDecoupling`, `runningEffort`, and `runningDataQuality` to Twig view context. |
| `templates/html/activity/activity--sport-type--run.html.twig` | Replaced basic split tables with four visual analytics sections: Running Pace Zones distribution bars, Aerobic Decoupling & HR Drift card, Relative Effort & Recovery Window, and Stream Data Integrity card. |
| `src/Domain/Integration/AI/NeuronAIAgent.php` | Redefined AI assistant persona as "Mark", an expert running coach & exercise physiologist with direct instructions to evaluate Zone 2 aerobic base vs Zone 4/5 tempo/intervals. |
| `src/Domain/Integration/AI/Tool/GetAllActivitiesSummary.php` | Enriched output to include `sportType` so the AI can distinguish runs from rides and walks. |
| `src/Domain/Integration/AI/Tool/GetGear.php` | Enhanced `get_gear` to query SQLite directly, grouping gear usage by `sportType` (exact km, count, and moving time for Running vs Walking vs Hiking) to prevent LLM hallucinations. |

### 2. Newly Created Domain & Application Files

| File | Purpose |
| :--- | :--- |
| `src/Domain/Athlete/PaceZone/PaceZone.php` | Value object for an individual pace zone (zone number, name, min/max sec/km, speed matching, formatted range). |
| `src/Domain/Athlete/PaceZone/PaceZones.php` | First-class collection of 5 zones; implements `fromThresholdPace` with Daniels/Friel percentages. |
| `src/Domain/Athlete/PaceZone/TimeInPaceZones.php` | Accumulator for time, percentage, distance, and avg pace per zone. |
| `src/Domain/Athlete/PaceZone/PaceZoneMode.php` | Enum for pace zone modes. |
| `src/Domain/Athlete/PaceZone/InvalidPaceZoneConfiguration.php` | Domain exception for malformed pace zone configurations. |
| `src/Domain/Athlete/PaceZone/PaceZoneConfiguration.php` | Parser and validator for athlete pace zone configuration arrays. |
| `src/Domain/Activity/Running/PaceZoneCalculator.php` | Resampling, time-weighting, pause exclusion, and 3-point velocity smoothing calculator. |
| `src/Domain/Activity/Running/AerobicDecouplingCalculator.php` | Steady-state validation, warm-up trimming, EF calculation, and decoupling percentage engine. |
| `src/Domain/Activity/Running/AerobicDecouplingResult.php` | Data transfer object containing half-by-half pace, HR, EF, decoupling %, drift bpm, and Friel assessment. |
| `src/Domain/Activity/Running/RaceTimePredictor.php` | Riegel power law predictor with recency scoring and distance similarity matching. |
| `src/Domain/Activity/Running/RacePrediction.php` | Value object representing a race prediction with uncertainty ranges and reference performance. |
| `src/Domain/Activity/Running/RunningEffortScore.php` | 0–120+ cardiovascular session effort score and recovery hours advisor. |
| `src/Domain/Activity/Running/RunningTrainingStatusAdvisor.php` | Fitness/fatigue/freshness coach translating CTL, ATL, TSB, and ACWR into human guidance. |
| `src/Domain/Activity/Running/RunningStreamValidator.php` | Telemetry auditor scoring stream data quality (0–100) and flagging GPS spikes / sensor drops. |
| `src/Domain/Activity/Running/RunningAnalyticsService.php` | Central orchestrator linking streams, database queries, threshold detection, and analytics. |

### 3. Newly Created AI Tools

| File | Tool Name | Purpose |
| :--- | :--- | :--- |
| `src/Domain/Integration/AI/Tool/GetAllRunsSummary.php` | `get_all_runs` | Returns all running activities with distance, duration, pace, HR, cadence, and elevation. |
| `src/Domain/Integration/AI/Tool/GetRunningRecords.php` | `get_running_records` | Queries all-time and recent PRs from 400m up to Marathon. |
| `src/Domain/Integration/AI/Tool/GetRunningTrends.php` | `get_running_trends` | Calculates weekly and monthly volume and pace trends. |
| `src/Domain/Integration/AI/Tool/GetRacePredictions.php` | `get_race_predictions` | Exposes Riegel race time predictions to conversational chat. |
| `src/Domain/Integration/AI/Tool/GetRunningAnalytics.php` | `get_running_analytics` | Exposes decoupling, effort scores, and training status to conversational chat. |

### 4. Newly Created Automated Test Suite

| File | Tests Covered |
| :--- | :--- |
| `tests/Domain/Athlete/PaceZone/PaceZonesTest.php` | Non-overlapping validation, speed matching, Daniels/Friel percentage calculations. |
| `tests/Domain/Activity/Running/PaceZoneCalculatorTest.php` | Time-weighting, pause exclusion, gap capping, and moving-average smoothing. |
| `tests/Domain/Activity/Running/AerobicDecouplingCalculatorTest.php` | Steady-state detection, warm-up trimming, EF calculation, and decoupling eligibility. |
| `tests/Domain/Activity/Running/RaceTimePredictorTest.php` | Riegel power law calculations, recency weighting, and uncertainty ranges. |
| `tests/Domain/Activity/Running/RunningEffortScoreTest.php` | Zone weighting, duration scaling, and recovery window bounds. |
| `tests/Domain/Activity/Running/RunningTrainingStatusAdvisorTest.php` | CTL/ATL/TSB classification into Peaking, Productive, Overreaching, and Detraining. |

### 5. Runtime & Development Configuration Files

| File | Purpose |
| :--- | :--- |
| `docker-compose.override.yml` | Live development mount overriding container `./src`, `./templates`, and `./tests`. |
| `bin/phpunit.phar` | Standalone PHPUnit 11 executable for running unit tests without Docker. |
| `AI_AGENT_GUIDE.md` | Guide for configuring the Neuron AI assistant with running coach capabilities. |
| `FORK_AND_ARCHITECTURE_GUIDE.md` | This document. |
| `RUNWISE_SYSTEM_DESIGN_AND_DEVELOPER_GUIDE.md` | Full system design and integration guide for the Android companion app. |

### 6. Mobile REST API & Companion Synchronization Subsystem

| File | Purpose |
| :--- | :--- |
| `src/Controller/Api/Mobile/MobileSyncRequestHandler.php` | High-performance mobile endpoints (`/api/mobile/sync`, delta queries, down-sync schemas, GPX export). |
| `src/Controller/Page/RunPlanRequestHandler.php` | Web UI controller for managing date-wise interval training schedules and .ics calendar feeds. |
| `templates/html/run-plan.html.twig` | Twig template for training schedule calendar and workout execution checklist. |
| `src/Application/AppName.php` | Application branding constant setting UI label to 'Runwise'. |
| `src/Application/Navigation/NavigationSection.php` | Sidebar navigation section adding 'Run Plan' to the web interface. |
| :--- | :--- |
| `docker-compose.override.yml` | Binds host `./src`, `./templates`, `./tests`, and `./bin` into the FrankenPHP container to allow live changes. |
| `bin/phpunit.phar` | Standalone PHPUnit 11 executable enabling full test suite execution inside the container. |
