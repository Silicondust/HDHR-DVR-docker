# HDHR-DVR-docker
Docker Wrapper for SiliconDust's HDHomeRun DVR Record Engine with UI for DVR Management

Source: https://github.com/Silicondust/HDHR-DVR-docker (see [Source and License](#source-and-license))

Image: `ghcr.io/silicondust/hdhrdvr-docker` (also on Docker Hub as `demonrik/hdhrdvr-docker` for now - see [Images](#images))

Image based on Alpine Linux https://alpinelinux.org/
The image uses Alpine 3.24 with PHP 8.5, and NGINX.

This container image built does not contain a binary of the silicondust record engine. It is assumed since the user has the ability to download the docker image then they have internet capability to get the latest engine - and thus the image contains a script to download the latest engine when the engine is started. Besides, you need internet for the DVR anyway.
To update the engine stop the container and then start it again and it will get the latest - or use the engine update on the Dashboard (see [Updating the Record Engine](#updating-the-record-engine)), which doesn't restart the container.
Note - when the container is restarted the nginx and php configurations will be restored to defaults. 

Is important for HDHomeRun system to have everything on the same Network to scan for tuners, etc.
thus run the container with the host network selected, i.e.
```
--network host
```

### Device Discovery
The Management GUI discovers devices the way SiliconDust recommends, in this order - each device is
added by the first method that finds it, and its `discover.json` is then read:
1. **localhost** - the record engine in this container, on `http://127.0.0.1:<Port>/discover.json`.
2. **UDP IPv4** - tuners and record engines are found by UDP broadcast (port 65001) using a native PHP
   implementation of the HDHomeRun discovery protocol (the same protocol SiliconDust's
   [libhdhomerun](https://github.com/Silicondust/libhdhomerun) uses). Legacy devices without an HTTP
   interface are skipped.
3. **mDNS IPv4** - every HDHomeRun answers for `hdhomerun.local`, so it is looked up with multicast DNS
   (224.0.0.251), which also finds devices on another subnet when the network relays mDNS.
4. **UDP IPv6** - the same discovery, multicast to `ff02::176` (link-local) and `ff05::176` (site-local)
   on each IPv6 interface, as libhdhomerun does. Only when the container has IPv6.
5. **mDNS IPv6** - `hdhomerun.local` looked up with multicast DNS over IPv6 (`ff02::fb`).
6. **cloud** - the cloud discovery service (`https://api.hdhomerun.com/discover`), as a fallback to find
   any devices the local network did not reach.

mDNS (both steps), IPv6 (both steps) and cloud discovery can each be turned off on the Settings page (see
[Settings](#settings)). The Diagnostics page shows what each step found, and which method found each device.
The local steps need the container on the host network (`--network host`); for IPv6 the host network must have IPv6.

In addition to the DVR engine, the container also has an embedded NGINX web server with PHP FPM configured to deliver a simple Management GUI.
By default the Web server will run on Port 80. Use the DVRUI_PORT environment variable to move this
to a free port in the case where there is a conflict with another container, or the host.
Avoid ports that browsers block as unsafe (e.g. 10080 gives ERR_UNSAFE_PORT in Chrome, Edge, Vivaldi and Firefox).

Use the DVRUI_ENABLE environment variable to choose how much of the UI runs:

| DVRUI_ENABLE | UI |
| --------| ------- |
| full (default) | The whole UI - Dashboard, Recordings (with the series pages and their actions), Rules (the account's recording rules - add a rule by searching the guide (a whole series or the current season - on all channels or one, and for sports only the games with chosen teams - or a single airing), edit their channels, teams, seasons and padding, change their priority or delete them) and Diagnostics |
| minimal | The Dashboard and Diagnostics only |
| none | No UI at all - NGINX and PHP FPM are not started, the container just runs the record engine. DVRUI_PORT and DVRUI_TZ aren't needed (PUID/PGID still apply to the engine) |

### Settings
The cog button at the right of the menu (next to the Reload and Diagnostics buttons) opens the Settings page,
in both the full and minimal UI. Each setting has a switch that saves as soon as it is changed, and takes
effect on the next page or Reload:

| Setting | Default | |
| --------| ------- | ------- |
| mDNS discovery | On | Look up `hdhomerun.local` with multicast DNS (IPv4, and IPv6 when IPv6 discovery is on) |
| IPv6 discovery | On | Discover devices over IPv6 when the container has IPv6 |
| Cloud discovery | On | Ask the SiliconDust cloud for devices local discovery did not reach |
| Record | All episodes | What a new rule records - All episodes, Current season or New episodes only (first aired on or after the day the rule is added, so reruns of older episodes are skipped). The New Rule dialog starts with this; it can be changed there for each rule |
| Insert Priority | Lowest | Where a new rule goes in the priority order - Lowest (bottom) or Highest (top, under one-time recordings). When rules want the same tuner at the same time, the higher one records. The New Rule dialog starts with this |
| Start padding | 60 seconds | How early a recording starts - the padding the New Rule dialog starts with (it can be changed there for each rule); at most 3600 |
| End padding | 60 seconds | How late a recording ends - the padding the New Rule dialog starts with (it can be changed there for each rule); at most 10800 |
| Sports end padding | 1800 seconds | The end padding the New Rule dialog uses for sports (a show whose games have teams), as games often run over; at most 10800 |
| SiliconDust server cache | 30 seconds | How long answers from the SiliconDust servers (recording rules, guide searches, upcoming episodes, cloud discovery) are reused before asking again, so moving between pages doesn't keep asking for the same thing. The Reload button, the Diagnostics page and changes made in the UI (adding, moving, editing or deleting a rule) clear the cache, so they always get fresh answers. 0 turns the cache off; at most 600 |

The settings are kept in `dvrui_settings.json` in /dvrdata, so they last through container restarts and updates.
The defaults are in `ui/vars.php`.

You can override the user ID and group ID used by the container processes by setting the PUID/PGID to match a user on your system.
By default NGINX will run as a user 'nginx'' and PHP as 'nobody' while the DVR will run simply as root or mapped if you use the --user settings of docket.
If specified - then everything in /dvrdata and /dvrrec will be changed to the new user IDs.

## Updating

### Updating the Record Engine
Every time the container starts, the container downloads SiliconDust's latest engine - the release, and the beta too when `BetaEngine=1` in the dvr.conf - and installs whichever is newest, if it is newer than the engine installed.

The Dashboard can also check for a newer engine and update to it without restarting the container - use the button below the config update button, next to the record engine parameters:
- **Already the latest** - nothing happens; the status bar shows the engine version running and the latest from SiliconDust.
- **Newer, and nothing recording** - the record engine is restarted, installs the newer engine as it starts, and the header shows the new version once it is running.
- **Newer, but recordings are in progress or about to start** - you are asked whether to update anyway, with a list of the recordings. Updating restarts the engine, so recordings in progress (or starting during the update) are interrupted.

The update is available in the full and minimal UI. The UI restarts the engine through supervisord's control socket (`/run/supervisord.sock`), which only root and the PHP user's group can use.

### Updating the Container
At this time there isn't much stored in the /dvrdata that needs to persist through an update.
Yes it would be useful to maintain the Storage UUID in the dvr.conf, but is safe to pretty much delete everything in the /dvrdata between updates (with a container stop and start) and it will be reconstructed on start. You may just need to update the params once more if you change from defaults. 

## Volumes
| Volume | Description |
| --------| ------- |
| dvrrec | Recordings and the engine logs will be stored here |
| dvrdata | Temporary data such as the engine itself, the config file, and a log output of the containers script |

## Environment Variables
| Variable | Description |
| --------| ------- |
| DVRUI_PORT | Override the default port of 80 for the embedded NGINX Server |
| PUID | Override the default user ID for the DVR |
| PGID | Override the default group ID for the DVR (used along with PUID) |
| DVRUI_TZ | Set the PHP timezone for the UI, e.g. Europe/Lisbon (defaults to UTC) |
| DVRUI_ENABLE | How much of the UI to run: none, minimal (Dashboard and Diagnostics) or full (defaults to full) |

## Docker Run Example
```
docker run -d --name dvr \
  --restart=unless-stopped \
  --network host \
  -e DVRUI_PORT=18080 \
  -e PUID=1000 \
  -e PGID=1000 \
  -e DVRUI_TZ=Europe/Lisbon \
  -e DVRUI_ENABLE=full \
  -v /path/to/hdhomerun/tempdata:/dvrdata \
  -v /path/to/hdhomerun/recordings:/dvrrec \
  ghcr.io/silicondust/hdhrdvr-docker
```

### Minimal Example (record engine only)
With DVRUI_ENABLE=none there is no UI or web server, so DVRUI_PORT and DVRUI_TZ aren't needed - just the volumes
(add PUID/PGID if the engine should run as a particular user):
```
docker run -d --name dvr \
  --restart=unless-stopped \
  --network host \
  -e DVRUI_ENABLE=none \
  -v /path/to/hdhomerun/tempdata:/dvrdata \
  -v /path/to/hdhomerun/recordings:/dvrrec \
  ghcr.io/silicondust/hdhrdvr-docker
```

## Images
The image is published on the GitHub Container Registry for amd64, arm64 and arm/v7 (32-bit, e.g. older Raspberry Pi), tagged with the version and `latest`:
```
docker pull ghcr.io/silicondust/hdhrdvr-docker:latest
```

### Moving from Docker Hub
Now that the project is maintained by SiliconDust, the image is released through the GitHub Container Registry as `ghcr.io/silicondust/hdhrdvr-docker`, starting with 3.5.0.
Releases are still published on Docker Hub as `demonrik/hdhrdvr-docker` for a while, so existing containers keep updating - but please move to ghcr.io.

To move, only the image name changes: keep the same volumes and environment variables, and the container carries on with the same config, engine and recordings. For example:
```
docker pull ghcr.io/silicondust/hdhrdvr-docker:latest
docker stop dvr && docker rm dvr
docker run -d --name dvr ... ghcr.io/silicondust/hdhrdvr-docker    # the same options as before
```
With docker compose, change the `image:` line to `ghcr.io/silicondust/hdhrdvr-docker` and run `docker compose up -d`.

## Source and License
The source is at https://github.com/Silicondust/HDHR-DVR-docker - originally written by Rik Dunphy, and now maintained by SiliconDust.
Report issues and send pull requests there.

To build the image yourself:
```
git clone https://github.com/Silicondust/HDHR-DVR-docker.git
cd HDHR-DVR-docker
docker build -t hdhrdvr-docker .
```

Released under the MIT License - see [LICENSE](LICENSE).
