#!/bin/mksh
# Script making incremental backups using rsync by Jean Weisbuch <jean@phpnet.org>
# Usage:	./backupServer.sh servername [--options] [directories list]
# Example:	./backupservers.sh web17 --exclude 'lockfile_*,pidfile' --port 22 /etc /usr/local/mysql /home/apache

# To do a dry run (simulation mode, the commands are not executed and it outputs commands that would have been executed between brackets) add the --dry-run option
# To manually backup, ex : rsync -va --delete --numeric-ids --inplace --exclude 'cache/zend/*' --exclude '*_log*' --exclude '*.log' --bwlimit=51200 -e 'ssh -i /root/.ssh/id_rsa_backups' --link-dest=/home/backups/web17/1/usr/local/ root@web17.sitenco.com:/usr/local/mysql /home/backups/web17/0/usr/local/

if [[ "$#" -lt 2 ]]; then
	print "ERROR: This script requires at least 2 arguments, exiting !" >&2
	exit 1
elif [[ $KSH_VERSION != @(\@\(#\)MIRBSD KSH R)@(4[1-9]|[5-9][0-9]|[1-9][0-9]+([0-9]))\ +([0-9])/+([0-9])/+([0-9])?(\ *) ]]; then
	print "ERROR: Requires mksh R41 or newer, exiting !" >&2
	exit 1
fi

[[ "$1" = -* ]] && print "ERROR: The first argument must be the server name, aborting." && exit 1

umask 077

# default settings, most can be overriden using arguments at launch (should also be excluded from server backup)
additionalOpts="--include='/home/dev' --exclude={mlocate.db,.{bash,nano,mysql,sqlite}_history,var/lib/varnish/\*,var/spool/postfix/active/\*,var/spool/postfix/bounce/\*,var/spool/postfix/defer/\*/\*,var/spool/postfix/deferred/\*/\*,NOBACKUP/,/boot/\*,vservers/templates/\*,/vservers/\*/dev/\*,postfix/dev/\*,cache/zend/\*,\*_log\*,\*.log,var/log/\*,var/lib/vnstat/\*,logs-routagenode/\*,logs/\*,sys/\*,proc/\*,\*.rrd,lost+found,tmp/\*,var/lib/arpwatch/\*,mnt/\*,media/\*,lib/udev/devices/\*,var/cache/man/\*,.svn/,\*.iso,var/cache/apt/\*,var/lib/apt/lists/\*}"
presharedKeyFile="/root/.ssh/id_rsa_backups"
bwLimit="51200" # 50mbps
backupDir="/home/backups"
sshPort="22"
sshConnectTimeout="4"
serverDomain="mycompany.com"

function runCmd() {
	# function to execute commands, if $dryRun is true it will only output the command and not execute them
	if [[ -z "$dryRun" ]]; then
		eval "$*"
	else
		print -n "[$*]"
	fi
}

function backupHost() {
	# we first test that rsync has access to the distant server to avoid removing backups without creating a new one
	rsync -q --dry-run -e "ssh -i $presharedKeyFile -p ${sshPort} -o ConnectTimeout=${sshConnectTimeout}" root@${serverIP}:/
	if [[ "$?" -gt 0 ]]; then
		print "ERROR: Cannot connect to the distant server (root@${serverIP}:${sshPort}) using rsync, backup aborted !" >&2
		exit 1
	fi

	print -n "[$(date +'%d.%m.%y %T')] Rotation for ${serverHostname} :"

	# to retreive the highest backup count
	if [[ -d "${backupDir}/${serverHostname}/0/" ]]; then
		typeset -i backupNum="$(ls --sort=version -r ${backupDir}/${serverHostname}/ |head -1)"
		if [[ "$backupNum" -gt 0 ]]; then
			typeset incrementalBackup=true
		fi
	else
		backupNum=0
		print -n " [backup dir was not existing]"
	fi
	typeset EPOCHSTARTTIME="${EPOCHREALTIME::10}"
	if [[ -d "${backupDir}/${serverHostname}/${backupNum}/" ]]; then
		# we remove the oldest backup directory
		print -n " d${backupNum}"
		runCmd "rm -rf ${backupDir}/${serverHostname}/${backupNum}/"
#	else
#		print "ERROR: Something is wrong with the backupNum : ${backupDir}/${serverHostname}/${backupNum}/, exiting !" >&2
#		exit 1
	fi
	while [[ "$backupNum" -gt 0 ]]; do
		# loop that does the directories rotation
		typeset -i NEWbackupNum="$((backupNum-1))"
		print -n " $NEWbackupNum"
		if [[ -d "${backupDir}/${serverHostname}/${NEWbackupNum}/" ]]; then
			runCmd "mv ${backupDir}/${serverHostname}/${NEWbackupNum}/ ${backupDir}/${serverHostname}/${backupNum}/"
		else
			runCmd "mkdir -p ${backupDir}/${serverHostname}/${backupNum}/"
		fi
		((backupNum--))
	done
	runCmd "mkdir -p ${backupDir}/${serverHostname}/0/"
	print -n "  backuping :"

	while [[ "$#" -gt 0 ]]; do
		# loop each directories to backup

		# to properly clean trailing slashes for rsync
		eval "$(print "${1}" |perl -pe 's#^/(.*?)((?<=/)([^/]*?))?/?$#DIR1=\047\1\047 DIR2=\047\3\047#')"
		DIR1="${DIR1%/}"
		if [[ -n "$DIR1" ]]; then
			FULLDIR="${DIR1}/${DIR2}"
			DIR1="${DIR1}/"
		else
			FULLDIR="${DIR2}"
		fi
		if [[ ! -d "${backupDir}/${serverHostname}/0/${FULLDIR}/" ]]; then
			runCmd "mkdir -p '${backupDir}/${serverHostname}/0/${FULLDIR%/*}/'"
		fi
		[[ -z "$FULLDIR" ]] && additionalOpts+=" --exclude='/dev/*'" || [[ "$FULLDIR" = vservers/+([!/]) ]] && additionalOpts+=" --exclude='/*/dev/*'"
		[[ -n "$incrementalBackup" ]] && additionalOpts+=" --link-dest='${backupDir}/${serverHostname}/1/${DIR1}'"
		# we replace whitespaces on distant paths with ? because rsync doesnt works well with whitespace in distant paths
		runCmd "rsync -a --delete --numeric-ids $additionalOpts --bwlimit=${bwLimit} -e \"ssh -i $presharedKeyFile -p ${sshPort} -o ConnectTimeout=${sshConnectTimeout}\" root@${serverIP}:\"/$(print "${FULLDIR}" |perl -pe 's/ /?/g')\" \"${backupDir}/${serverHostname}/0/${DIR1}\""
		if [[ "$?" -ne 0 ]]; then
			print "\nERROR: rsync ended with an error code != 0 for directory '/${FULLDIR}', exiting !" >&2
			exit 1
		fi
		shift
	done
	print " (took $((#${EPOCHREALTIME::10}-EPOCHSTARTTIME)) secs)"
	touch "${backupDir}/${serverHostname}/0/"
}

serverHostname="$1"
shift

# command line arguments parsing
while [[ "$1" = -+(*) ]]; do
	typeset TEMPARG="${1##+(-)}"
	case "$TEMPARG" {
		("verbose"|"progress")
			additionalOpts+=" --${TEMPARG}";;
		("port")
			if [[ "$2" = +([0-9]) ]]; then
				sshPort="$2"
#				additionalOpts+=" -e 'ssh -p ${2}'"
				shift
			else
				print "ERROR: Invalid port specified, exiting !" >&2
				exit 1
			fi
			;;
		("dry-run"|"test")
			dryRun=true;;
		("fullhost")
			if [[ -n "$2" && "$2" = +([!-])* ]]; then
				if [[ "$2" = +([0-9.]) ]]; then
					# a server IP has been specified
					serverIP="$2"
				else
					if [[ "$2" = +([a-Z0-9]) ]]; then
						# a server hostname has been specified (subdomains wont be taken in account)
						serverFQDN="${2}.${serverDomain}"
					else
						# a server FQDN has been specified
						serverFQDN="$2"
					fi
				fi
				shift
			else
				FULLHOST=true
			fi
			;;
		("xattrs")
			additionalOpts+=" -X";;
		("bwlimit")
			bwLimit="$2"
			shift;;
		("exclude")
			additionalOpts+=" --exclude='${2}'"
			shift;;
		("compress")
			additionalOpts+=" -z";;
		("sparse")
			additionalOpts+=" -S";;
		("inplace")
			additionalOpts+=" --inplace";;
		(*)
			print "ERROR: Unknown argument : ${TEMPARG}, exiting !" >&2
			exit 1;;
	}
	shift
done

if [[ -z "$serverFQDN" ]]; then
	if [[ -n "$FULLHOST" ]]; then
		typeset -n serverFQDN="serverHostname"
		unset FULLHOST
	else
		serverFQDN="${serverHostname}.${serverDomain}"
	fi
fi

# to check if the host provided is resolving and does not resolves to a wildcard DNS such as *.$serverDomain
[[ -z "$serverIP" ]] && serverIP="$(dig $serverFQDN +short |tail -1)"
if [[ -z "$serverIP" ]]; then
	print "ERROR: The DNS $serverFQDN does not seems to be existing or correctly configured (${serverIP}), exiting !" >&2
	exit 1
elif [[ "$serverHostname" = -+(*) ]]; then
	print "ERROR: The correct syntax is : $0 servername [--options] [directories list]" >&2
	exit 1
fi

backupHost "$@"
