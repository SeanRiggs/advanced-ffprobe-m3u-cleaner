# Advanced FFprobe M3U Cleaner

An automated solution for cleaning and validating M3U playlists using FFprobe.

## Quick Start for Users

1. Install Docker on your system if not already installed.

2. Pull the Docker image:

   ```bash
   docker pull seanriggs/advanced-ffprobe-m3u-cleaner:latest
   ```

3. Set up the directory structure:

   ```bash
   mkdir m3u-cleaner && cd m3u-cleaner
   mkdir m3u output logs
   ```

4. Place your M3U playlist(s) in the `m3u` directory.

5. Create a `docker-compose.yml` file in the `m3u-cleaner` directory with the following content:

   ```yaml
   version: '3'
   services:
     m3u-cleaner:
       image: seanriggs/advanced-ffprobe-m3u-cleaner:latest
       volumes:
         - ./m3u:/app/m3u
         - ./output:/app/output
         - ./logs:/var/log
   ```

6. Run the cleaner:

   ```bash
   docker compose up
   ```

7. Find your cleaned playlist in the `output` directory.

Note: Ensure Docker is installed on your system before starting.

## For Windows Users with Docker Desktop

1. Open Docker Desktop.
2. Go to the "Images" tab and search for "seanriggs/advanced-ffprobe-m3u-cleaner".
3. Click "Pull" to download the image if not already present.
4. Once pulled, click "Run".
5. In "Optional settings", add these volume mappings:
   - Host path: `C:\path\to\your\m3u-cleaner\m3u` -> Container path: `/app/m3u`
   - Host path: `C:\path\to\your\m3u-cleaner\output` -> Container path: `/app/output`
   - Host path: `C:\path\to\your\m3u-cleaner\logs` -> Container path: `/var/log`
6. Click "Run" to start the container.

Replace `C:\path\to\your` with your actual directory path.

## Features

- Automated M3U stream validation using FFprobe
- Duplicate entry removal
- Playlist cleaning and organization

## What the Cleaner Does

During the FFprobe process, this cleaner:

- Checks for valid video and audio streams
- Verifies stream accessibility and playability
- Analyzes stream metadata for format compatibility

What it doesn't do:

- Content analysis or filtering
- Long-term stability monitoring
- Bandwidth or quality checks

## Environment Variables

- `M3U_DIRECTORY`: Input M3U files directory (default: `/app/m3u`)
- `OUTPUT_FILE`: Cleaned playlist path (default: `/app/output/cleaned_playlist.m3u`)
- `LOG_FILE`: Log file path (default: `/var/log/validator.log`)

## For Developers

1. Clone the repository:

   ```bash
   git clone https://github.com/seanriggs/advanced-ffprobe-m3u-cleaner.git
   cd advanced-ffprobe-m3u-cleaner
   ```

2. For development, use `docker-compose.dev.yml`:

   ```bash
   docker compose -f docker-compose.dev.yml up --build
   ```

3. Make changes to `validator.php` or other files as needed.

4. To build and push a new version:

   ```bash
   docker build -t seanriggs/advanced-ffprobe-m3u-cleaner:latest .
   docker push seanriggs/advanced-ffprobe-m3u-cleaner:latest
   ```

## Project Structure

- `docker-compose.yml`: Production configuration
- `docker-compose.dev.yml`: Development configuration
- `Dockerfile`: Container image definition
- `validator.php`: Main script for playlist cleaning
- `composer.json`: PHP dependencies

## Support

For issues or feature requests, please visit our [GitHub repository](https://github.com/seanriggs/advanced-ffprobe-m3u-cleaner).
