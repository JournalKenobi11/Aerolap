# Dreeve AI Running Coach & Analytics Integration Guide

Comprehensive technical reference documenting all modifications made to Dreeve to enable full running analysis, long-term progression tracking, personal records, and persona customization.

---

## 1. Problem & Architecture Overview

### Why Dreeve Originally Failed to Analyze All Runs
1. **Empty Activity Summaries**: The default `get_activities_summary` tool (`GetAllActivitiesSummary.php`) only returned `['id' => ..., 'on' => ...]`. It omitted `sportType`, distance, moving time, pace, and heart rate. The AI had no way of knowing which activities were runs without calling individual endpoints.
2. **Hard-coded 25-Activity Limit**: `GetActivity.php` had `public function getMaxRuns(): int { return 25; }`. An LLM could not fetch more than 25 activities in a single prompt turn.
3. **No Running-Specific or Aggregate Tools**: There were no tools for personal records (PRs), weekly/monthly mileage progression, or kilometer splits analysis.
4. **Agent Persona Constraints**: The default prompt instructed the model to ask the user for a specific Strava activity ID rather than acting as a proactive data analyst.

---

## 2. Inventory of Changes Made to Dreeve

### A. Container Mounts (`docker-compose.override.yml`)
* **File**: `docker-compose.override.yml`
* **Change**: Added `./src:/var/www/src:ro` to both `app` and `daemon` services.
* **Why**: The upstream Docker image (`robiningelbrecht/dreeve:latest`) bakes PHP code into the image. Mounting `./src` into `/var/www/src:ro` allows host code modifications to be reflected in the running container without rebuilding the Docker image.

```yaml
services:
  app:
    image: robiningelbrecht/dreeve:latest
    build: !reset null
    container_name: dreeve
    volumes:
      - ./src:/var/www/src:ro
    networks:
      - statistics-for-strava-network
      - homelab
  daemon:
    image: robiningelbrecht/dreeve:latest
    build: !reset null
    container_name: dreeve-daemon
    volumes:
      - ./src:/var/www/src:ro
    networks:
      - statistics-for-strava-network

networks:
  homelab:
    name: homelab
    external: true
```

---

### B. New AI Tool: `GetAllRunsSummary` (`get_all_runs`)
* **File**: `src/Domain/Integration/AI/Tool/GetAllRunsSummary.php`
* **Tagged As**: `app.ai_tool` (automatically discovered by Symfony via `NeuronAI\Tools\ToolInterface`)
* **Purpose**: Fetches all running workouts (`sportType = 'Run'`) from SQLite with rich metrics.
* **Returned Data**:
  * `id`: Activity ID string
  * `date`: Start date & time (`Y-m-d H:i`)
  * `name`: Workout title (e.g., "Morning Run", "L1 Instruction Cache")
  * `distance_km`: Total distance in kilometers (rounded to 2 decimal places)
  * `duration`: Moving time formatted (e.g. `1h 00m 35s` or `45:12`)
  * `pace`: Average pace formatted (`MM:SS /km`)
  * `avg_hr`: Average heart rate (bpm)
  * `max_hr`: Max heart rate (bpm)
  * `avg_speed_kmh`: Average speed in km/h
  * `cadence`: Average cadence (spm)
  * `elevation_m`: Total elevation gain (meters)
* **Parameters**:
  * `limit` (default: 100, max: 250)
  * `fromDate` (`YYYY-MM-DD`, optional)
  * `toDate` (`YYYY-MM-DD`, optional)

---

### C. New AI Tool: `GetRunningRecords` (`get_running_records`)
* **File**: `src/Domain/Integration/AI/Tool/GetRunningRecords.php`
* **Tagged As**: `app.ai_tool`
* **Purpose**: Queries `ActivityBestEffort` table joined with `Activity`.
* **Standard Benchmarks Tracked**:
  * `400m`
  * `804m` (1/2 Mile)
  * `1000m` (1 km)
  * `1609m` (1 Mile)
  * `3218m` (2 Miles)
  * `5000m` (5 km)
  * `10000m` (10 km)
  * `15000m` (15 km)
  * `21097m` (Half Marathon)
  * `42195m` (Marathon)
* **Returned Data**:
  * Benchmark distance label & meters
  * Fastest time formatted (`MM:SS` or `H:MM:SS`)
  * Benchmark pace (`MM:SS /km`)
  * Date achieved & activity name

---

### D. New AI Tool: `GetRunningTrends` (`get_running_trends`)
* **File**: `src/Domain/Integration/AI/Tool/GetRunningTrends.php`
* **Tagged As**: `app.ai_tool`
* **Purpose**: Aggregates running data over time for training load analysis:
  * **Monthly Trends**: Last 12 months (runs count, total km, total moving hours, average pace, average heart rate).
  * **Weekly Trends**: Last 12 weeks (weekly mileage, run count, average pace, average heart rate).

---

### E. Enriched Existing Tool: `GetAllActivitiesSummary`
* **File**: `src/Domain/Integration/AI/Tool/GetAllActivitiesSummary.php`
* **Change**: Added `sport`, `name`, `distance_km`, `moving_time`, and `avg_hr` to the summary items so the model knows which workouts are Runs, Hikes, or Walks without making secondary API calls.

---

### F. Upgraded System Prompt & Instructions
* **File**: `src/Domain/Integration/AI/NeuronAIAgent.php`
* **Change**: Updated the agent instructions so it:
  1. Identifies as an expert running coach and exercise physiologist.
  2. Uses `get_all_runs` when asked for running progression or activity review.
  3. Uses `get_running_records` when asked for personal bests or benchmarks.
  4. Uses `get_running_trends` when asked for volume, consistency, or weekly/monthly mileage.
  5. Uses `get_activity_splits` or `get_most_recent_activity` for km-by-km pacing analysis.
  6. Analyzes aerobic base (Zone 2) vs threshold (Zone 4/5).

---

## 3. How to Customize "Mark" (Rename, Persona & Styling)

The AI persona is named "Mark" by default. You can change the name, personality, tone, avatar, and UI placeholders anytime.

### Step 1: Change Name & Coaching Personality in the Prompt
Open `src/Domain/Integration/AI/NeuronAIAgent.php`:

```php
    #[\Override]
    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                // Change "Mark" to your preferred name below:
                'You are an expert AI running coach, exercise physiologist, and workout analyst named "Coach Jordan". Do not allow users to call you any other name.',
                "Always take into account the athlete's heart rate zones (especially Zone 2 for aerobic base vs Zone 4/5 for tempo/intervals) and FTP whenever evaluating intensity.",
                // Custom personality rules:
                'Adopt an encouraging, analytical, and metric-focused coaching style. Point out negative splits and heart rate stability.',
            ],
            steps: [
                // Customize how the coach approaches problems...
            ],
            output: [
                // Customize output format (tables, bullet points, brevity)...
            ]
        );
    }
```

### Step 2: Change the Web UI Chat Placeholder
If you want the chat input box to say *"Ask Coach Jordan something..."* instead of *"Ask Mark something..."*:
1. Open `templates/html/chat/chat.html.twig`.
2. Look for line 41:
   ```twig
   placeholder="{{ "Ask Mark something..."|trans }}" data-placeholder-idle="{{ "Ask Mark something..."|trans }}"
   ```
   Change `"Ask Mark something..."` to your preferred placeholder text.
3. Or update the English translation string in `translations/messages+intl-icu.en_US.yaml`:
   ```yaml
   'Ask Mark something...': 'Ask Coach Jordan something...'
   ```

### Step 3: Change the AI Avatar Image
The avatar shown next to messages in the chat interface is located at:
* File path: `public/assets/images/mark.png`
* Web template reference: `templates/html/chat/message.html.twig` line 11:
  ```twig
  <img class="size-6 rounded-full border" src="{{ relativeUrl('assets/images/mark.png') }}" alt="Mark"/>
  ```
To replace the avatar:
* Simply replace `public/assets/images/mark.png` with any square PNG image (e.g. 128x128 px).

### Step 4: Change Terminal Console Output Name (Optional)
If you chat with the bot via terminal (`bin/console app:ai:agent-chat`):
* Open `src/Console/AIAgentChatConsoleCommand.php`.
* Update line 49 and line 77 to change the greeting and output tag from `<Mark>` to `<Coach Jordan>`.

---

## 4. Maintenance Commands

Whenever you add a new tool or modify `NeuronAIAgent.php`:

### 1. Clear Symfony Cache
```bash
docker exec dreeve bin/console cache:clear
```

### 2. Verify AI Tools Are Registered
```bash
docker exec dreeve bin/console debug:container --tag=app.ai_tool
```

### 3. Test Chat from Terminal
```bash
printf "What are my running records?\nexit\n" | docker exec -i dreeve bin/console app:ai:agent-chat
```

### 4. Restart Dreeve Container
```bash
cd /path/to/dreeve
docker compose up -d
```

---

## 5. Summary of Key Files

| File | Purpose |
| :--- | :--- |
| `src/Domain/Integration/AI/NeuronAIAgent.php` | Agent definition, system prompt, persona ("Mark"), and tool binding |
| `src/Domain/Integration/AI/Tool/GetAllRunsSummary.php` | Tool `get_all_runs`: returns all 64+ running workouts with paces and HR |
| `src/Domain/Integration/AI/Tool/GetRunningRecords.php` | Tool `get_running_records`: returns all personal bests (400m to Marathon) |
| `src/Domain/Integration/AI/Tool/GetRunningTrends.php` | Tool `get_running_trends`: returns weekly & monthly volume aggregations |
| `src/Domain/Integration/AI/Tool/GetAllActivitiesSummary.php` | Tool `get_activities_summary`: enriched overview of all activities |
| `templates/html/chat/chat.html.twig` | Web chat interface template & placeholder |
| `public/assets/images/mark.png` | Avatar image for the assistant |
| `docker-compose.override.yml` | Bind-mounts host `./src` into `/var/www/src:ro` |
