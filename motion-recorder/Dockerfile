FROM python:3.12-alpine
RUN apk add --no-cache ffmpeg
WORKDIR /app
COPY recorder.py /app/recorder.py
CMD ["python", "/app/recorder.py"]
