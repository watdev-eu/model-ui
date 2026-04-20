FROM dpage/pgadmin4:latest

USER root

RUN apt-get update \
 && apt-get install -y --no-install-recommends gettext-base \
 && rm -rf /var/lib/apt/lists/*

COPY servers.json.template /pgadmin4/servers.json.template
COPY entrypoint.sh /startup.sh
RUN chmod +x /startup.sh

ENTRYPOINT ["/startup.sh"]