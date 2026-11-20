FROM ubuntu:24.04
RUN apt-get update -qq && apt-get install -y -qq systemd systemd-sysv dbus iproute2 sudo curl ca-certificates openssh-server >/dev/null && rm -f /etc/machine-id && systemd-machine-id-setup
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
