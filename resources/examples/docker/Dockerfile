# A small web server built and run by Docker. Laravelify builds this image on the server and
# runs it on 127.0.0.1:<the site's port>. The app listens on PORT inside the container.
FROM python:3.12-alpine
WORKDIR /app
COPY server.py .
EXPOSE 8080
CMD ["python", "server.py"]
