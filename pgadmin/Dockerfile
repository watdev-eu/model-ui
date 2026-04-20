FROM dpage/pgadmin4:latest

COPY servers.json.template /pgadmin4/servers.json.template
COPY entrypoint.sh /startup.sh

USER root
RUN chmod +x /startup.sh

ENTRYPOINT ["/startup.sh"]
