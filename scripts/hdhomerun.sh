#!/bin/sh
# scripts/hdhomerun.sh
#
# Copyright (c) 2020 Rik Dunphy
# Copyright (c) 2026 Silicondust USA Inc.
# Released under the MIT License - see LICENSE

###########################
# hdhomerun.sh
# Shell Script to prepare the container data and execute the record engine
# Version 1.2
#  

# Parameters - make sure these match the DockerFile
HDHR_HOME=/HDHomeRunDVR
DVRData=${HDHR_HOME}/data
DVRRec=${HDHR_HOME}/recordings
DefaultPort=59090

# Download URLs from Silicondust - Shouldn't change much
DownloadURL=https://download.silicondust.com/hdhomerun/hdhomerun_record_linux
BetaURL=https://download.silicondust.com/hdhomerun/hdhomerun_record_linux_beta

# Some additional params you can change
DVRConf=dvr.conf
DVRBin=hdhomerun_record
DVR_PFX="DVRMgr: "

# This script's messages also go to a small log the UI shows on the Diagnostics
# page (the container output, docker logs, can't be read from the UI)
DVRMgrLog=/tmp/dvrmgr.log

log_msg()
{
	echo ${DVR_PFX} "$@"
	echo "$(date '+%Y%m%d-%H:%M:%S') ${DVR_PFX}$*" >> ${DVRMgrLog} 2>/dev/null
}

# keep the log short - the last 200 lines, from earlier starts
start_log()
{
	if [ -f ${DVRMgrLog} ] ; then
		tail -n 200 ${DVRMgrLog} > ${DVRMgrLog}.tmp 2>/dev/null && mv -f ${DVRMgrLog}.tmp ${DVRMgrLog}
	fi
	touch ${DVRMgrLog}
	chmod 644 ${DVRMgrLog}   # readable by the UI
}

###########################
# Creates the initial config file for the engine 8in /HDHomeRunDVR/data
# Sets Following defaults
#   RecordPath =  /HDHomeRunDVR/recordings  # Should always be this
#   Port = 59090                            # must match the Dockerfile
#   RecordStreamsMax=16                     # Enable max recordings
#   BetaEngine=1                            # Used by this script
#
create_initial_config()
{
	log_msg "** Creating Initial Config File"
	touch  ${DVRData}/${DVRConf}
	echo "RecordPath=${DVRRec}" >> ${DVRData}/${DVRConf}
	echo "Port=${DefaultPort}" >> ${DVRData}/${DVRConf}
	echo "RecordStreamsMax=16" >>  ${DVRData}/${DVRConf}
	echo "BetaEngine=1" >>  ${DVRData}/${DVRConf}
}

###########################
# Verifies the config file dvr.conf exists in /HDHomeRunDVR/data and ensure
# is writable so Engine can update the StorageID
# If the file doesnt exist, create one.
#
validate_config_file()
{
	log_msg "** Validating the Config File is available and set up correctly"
	if [[ -e ${DVRData}/${DVRConf} ]] ; then
		log_msg "Config File exists and is writable - is record path and port correct"
		.  ${DVRData}/${DVRConf}
		# TODO: Validate RecordPath
		# TODO: Validate Port
	else
		# config file is missing
		log_msg "Config is missing - creating initial version"
		create_initial_config
		.  ${DVRData}/${DVRConf}
	fi
}

###########################
# Get latest Record Engine(s) from SiliconDust and install the newest
# Downloads the released engine, and the beta too when BetaEngine=1 in the conf,
# and installs whichever is newest if it's newer than the installed engine.
# Runs every time the engine starts - the UI's engine update restarts the dvr
# program so this installs the newer engine (the UI compares versions the same
# way, see ui/includes/dvrui_engine_update.php)
#

# A number that orders versions - 20261107 => 2026110700, 20261107beta2 => 2026110702
# (0 if it isn't a version)
version_key()
{
	echo "$1" | awk '{
		if (match($0, /^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]/)) {
			date = substr($0, 1, 8); beta = 0
			if (match($0, /^[0-9]+beta[0-9]+/)) {
				beta = substr($0, 13) + 0
				if (beta > 99) beta = 99
			}
			printf "%s%02d\n", date, beta
		} else {
			print 0
		}
	}'
}

# The version an engine file reports (empty if it isn't one)
engine_version()
{
	if [ -s "$1" ] ; then
		sh "$1" version 2>/dev/null | awk 'NR==1{print $4}'
	fi
}

# Download an engine from $1 to $2 - fails (and removes $2) if the download fails
download_engine()
{
	rm -f "$2"
	if wget -qO "$2" "$1" && [ -s "$2" ] ; then
		return 0
	fi
	rm -f "$2"
	return 1
}

update_engine()
{
	log_msg "** Installing the HDHomeRunDVR Record Engine"
	curEngineVer=`engine_version ${DVRData}/${DVRBin}`
	if [ -z "$curEngineVer" ] ; then
		curEngineVer=0
	fi
	curKey=`version_key $curEngineVer`
	log_msg "** Current Engine Version is ${curEngineVer}"

	bestFile=""
	bestVer=""
	bestKey=0

	log_msg "Downloading latest Stable release"
	if download_engine ${DownloadURL} ${DVRData}/${DVRBin}_rel ; then
		stableEngineVer=`engine_version ${DVRData}/${DVRBin}_rel`
		stableKey=`version_key $stableEngineVer`
		log_msg "Got Engine [$stableEngineVer]"
		if [ $stableKey -gt 0 ] ; then
			bestFile=${DVRData}/${DVRBin}_rel
			bestVer=$stableEngineVer
			bestKey=$stableKey
		fi
	else
		log_msg "ERROR in downloading latest Stable DVR binary"
	fi

	if [ "$BetaEngine" = "1" ]; then
		log_msg "Downloading latest Beta release"
		if download_engine ${BetaURL} ${DVRData}/${DVRBin}_beta ; then
			betaEngineVer=`engine_version ${DVRData}/${DVRBin}_beta`
			betaKey=`version_key $betaEngineVer`
			log_msg "Got Engine [$betaEngineVer]"
			if [ $betaKey -gt $bestKey ] ; then
				bestFile=${DVRData}/${DVRBin}_beta
				bestVer=$betaEngineVer
				bestKey=$betaKey
			fi
		else
			log_msg "ERROR in downloading latest Beta DVR binary"
		fi
	fi

	if [ -z "$bestFile" ] ; then
		log_msg "ERROR have no downloaded engines - leaving the existing binary [$curEngineVer] alone!"
	elif [ $bestKey -gt $curKey ] ; then
		log_msg "Downloaded engine [$bestVer] is newer than [$curEngineVer] - updating..."
		rm -f ${DVRData}/${DVRBin}
		mv $bestFile ${DVRData}/${DVRBin}
	else
		log_msg "Downloaded engine [$bestVer] is not newer than [$curEngineVer] - not updating"
	fi
	rm -f ${DVRData}/${DVRBin}_beta ${DVRData}/${DVRBin}_rel
	if [ -f ${DVRData}/${DVRBin} ] ; then
		# readable and runnable by all - the UI (web user) asks it for its version
		chmod 755 ${DVRData}/${DVRBin}
		EngineVer=`engine_version ${DVRData}/${DVRBin}`
		log_msg "Engine is ... " ${EngineVer}
	else
		log_msg "ERROR no record engine is installed"
	fi
}

###########################
# Patch Permissions to the dvr user
#
patch_permissions()
{
	log_msg "** Checking for PUID"
	/usr/bin/getent passwd ${PUID} > /dev/null
    if [ $? -eq 0 ] ; then
		log_msg "** PUID user exists - adjusting permissions to dvrdata & dvrrec"
		chown -R dvr:dvr /dvrdata /dvrrec
	else
		log_msg "** Something went wrong - PUID provided, but no user created. using default"
	fi
}

###########################
# Start the engine in foreground, redirect stderr and stdout to the logfile
# The engine file is a shell script that unpacks the engine to /tmp and runs it
# from there, so it's run through sh - it then only has to be readable, which
# works when /dvrdata is mounted noexec (common on NAS shares) or is a network
# share that ignores the chmod (exit status 126 / Permission denied otherwise)
#
start_engine()
{
	log_msg "** Starting the DVR Engine"
    if [ -n "${PUID}" ] ; then   # PGID is only used along with PUID
		patch_permissions
		/usr/bin/getent passwd ${PUID} > /dev/null
	    if [ $? -eq 0 ] ; then
			log_msg "** Executing DVR engine with PUID info..."
			su -c "/bin/sh ${DVRData}/${DVRBin} foreground --conf ${DVRData}/${DVRConf}" dvr
		else
			log_msg "** Something went wrong - PUID provided, but no user created. using default"
			/bin/sh ${DVRData}/${DVRBin} foreground --conf ${DVRData}/${DVRConf}
		fi
	else
		log_msg "** Executing DVR engine as root..."
		/bin/sh ${DVRData}/${DVRBin} foreground --conf ${DVRData}/${DVRConf}
	fi
	# the engine runs until it's stopped - its exit status says why it stopped
	# (126 = it couldn't be run: Permission denied, 127 = not found)
	log_msg "** DVR engine exited with status $?"
}

###########################
# Main loop
#
start_log
echo "**********************"
echo "hdhomerun.sh"
log_msg "** hdhomerun.sh starting (PUID=[${PUID}] PGID=[${PGID}])"
validate_config_file
update_engine
start_engine

