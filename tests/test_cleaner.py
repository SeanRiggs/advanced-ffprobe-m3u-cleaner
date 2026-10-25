"""Regression tests against local HTTP media, never third-party channel providers."""
import array
import contextlib
import functools
import http.server
import io
import math
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import time
import unittest
import wave

ROOT = Path(__file__).resolve().parents[1]
IMAGE = os.environ.get('CLEANER_IMAGE')


def docker_base():
    return ['docker', 'run', '--rm', '--network', 'host', '--user', f'{os.getuid()}:{os.getgid()}',
            '--volume', '/tmp:/tmp']


class Handler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_HEAD(self):
        self.send_error(405)

    def do_GET(self):
        if self.path.startswith('/slow'):
            time.sleep(4)
            self.send_error(503)
            return
        if self.path.startswith('/redirect'):
            self.send_response(302)
            self.send_header('Location', '/stream.wav')
            self.end_headers()
            return
        with contextlib.suppress(BrokenPipeError, ConnectionResetError):
            super().do_GET()


class CleanerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not IMAGE and not shutil.which('php'):
            raise RuntimeError('Set CLEANER_IMAGE to a built image, or install PHP CLI and FFprobe.')
        cls.fixture_tmp = tempfile.TemporaryDirectory()
        cls.fixtures = Path(cls.fixture_tmp.name)
        samples = array.array('h', (int(10000 * math.sin(i * 2 * math.pi * 440 / 8000)) for i in range(8000)))
        with wave.open(str(cls.fixtures / 'stream.wav'), 'wb') as audio:
            audio.setparams((1, 2, 8000, 8000, 'NONE', 'not compressed'))
            audio.writeframes(samples.tobytes())
        (cls.fixtures / 'bad.html').write_text('<html>audio video unavailable</html>')
        cmd = ['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=size=64x64:rate=10',
               '-t', '2', '-c:v', 'mpeg2video', '-g', '10', '-f', 'hls', '-hls_time', '1',
               '-hls_list_size', '0', str(cls.fixtures / 'stream.m3u8')]
        if IMAGE:
            cmd = docker_base() + ['--entrypoint', 'ffmpeg', IMAGE] + cmd[1:]
        subprocess.run(cmd, check=True, capture_output=True, timeout=90)
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), functools.partial(Handler, directory=str(cls.fixtures)))
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = f'http://127.0.0.1:{cls.server.server_port}'

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()
        cls.fixture_tmp.cleanup()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.inputs = self.root / 'input'
        self.inputs.mkdir()
        self.output = self.root / 'output' / 'cleaned.m3u'
        self.output.parent.mkdir()
        self.log = self.root / 'logs' / 'validator.log'

    def playlist(self, name='channels.m3u', url=None, title='Test Channel', tags=''):
        content = f'#EXTM3U\n#EXTINF:-1 {tags},{title}\n{url or self.base + "/stream.wav"}\n'
        (self.inputs / name).write_text(content)
        return content

    def run_cleaner(self, **overrides):
        settings = dict(M3U_DIRECTORY=str(self.inputs), OUTPUT_FILE=str(self.output),
                        LOG_FILE=str(self.log), PROBE_TIMEOUT_SECONDS='3')
        settings.update(overrides)
        env = dict(os.environ, **settings)
        if IMAGE:
            cmd = docker_base()
            for key in settings:
                cmd += ['-e', key]
            cmd += [IMAGE]
        else:
            cmd = ['php', str(ROOT / 'validator.php')]
        start = time.monotonic()
        result = subprocess.run(cmd, env=env, text=True, capture_output=True, timeout=30)
        self.assertNotIn('Warning:', result.stdout + result.stderr)
        self.assertNotIn('Fatal error:', result.stdout + result.stderr)
        return result, time.monotonic() - start

    def test_m3u_and_m3u8_and_uppercase(self):
        for index, extension in enumerate(['m3u', 'm3u8', 'M3U', 'M3U8']):
            self.playlist(f'{index}.{extension}', title=f'Channel{index}')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text().count('#EXTINF'), 4)

    def test_m3u8_alone_preserves_metadata_and_input(self):
        content = ('\ufeff#EXTM3U x-tvg-url="https://guide.example/epg.xml"\r\n'
                   '#EXTINF:-1 tvg-id="station.id" tvg-name="News, Local" tvg-logo="https://logo.example/icon.png" group-title="News" custom="keep",News, Local\r\n'
                   '#EXTVLCOPT:http-user-agent=ExamplePlayer\r\n'
                   '#KODIPROP:inputstream.adaptive.manifest_type=hls\r\n'
                   '#EXTGRP:News\r\n' + self.base + '/stream.wav\r\n')
        path = self.inputs / 'only.m3u8'
        path.write_bytes(content.encode())
        before = path.read_bytes()
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text(), content.lstrip('\ufeff').replace('\r\n', '\n'))
        self.assertEqual(path.read_bytes(), before)

    def test_exact_duplicates_keep_first_metadata_and_distinct_titles(self):
        self.playlist('a.m3u', tags='tvg-id="first"')
        self.playlist('b.m3u8', tags='tvg-id="second"')
        self.playlist('c.m3u', title='Another Channel')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 0, result.stderr)
        output = self.output.read_text()
        self.assertEqual(output.count('#EXTINF'), 2)
        self.assertIn('tvg-id="first"', output)
        self.assertNotIn('tvg-id="second"', output)

    def test_real_hls_video_redirect_and_head_rejection(self):
        self.playlist('video.m3u8', url=self.base + '/stream.m3u8', title='HLS')
        self.playlist('audio.m3u', url=self.base + '/redirect', title='Redirect')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text().count('#EXTINF'), 2)

    def test_invalid_media_is_removed_without_dropping_good_media(self):
        self.playlist('a.m3u', url=self.base + '/bad.html', title='Invalid')
        self.playlist('b.m3u8', title='Valid')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Valid', self.output.read_text())
        self.assertNotIn('Invalid', self.output.read_text())

    def test_empty_input_retains_previous_output(self):
        self.output.write_text('LAST GOOD')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(self.output.read_text(), 'LAST GOOD')

    def test_unrelated_files_are_ignored(self):
        self.playlist('ignored.m3u8.bak')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 1)
        self.assertFalse(self.output.exists())

    def test_all_invalid_retains_previous_output(self):
        self.output.write_text('LAST GOOD')
        self.playlist(url=self.base + '/bad.html')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 2)
        self.assertEqual(self.output.read_text(), 'LAST GOOD')

    def test_stalled_request_is_bounded_and_next_channel_is_processed(self):
        self.playlist('a.m3u', url=self.base + '/slow', title='Stalled')
        self.playlist('b.m3u8', title='Working')
        result, elapsed = self.run_cleaner(PROBE_TIMEOUT_SECONDS='1')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertLess(elapsed, 10)
        self.assertIn('Working', self.output.read_text())
        self.assertNotIn('Stalled', self.output.read_text())

    def fake_probe(self, body):
        script = self.root / 'fake-ffprobe'
        script.write_text('#!/bin/sh\nif [ "$1" = "-version" ]; then exit 0; fi\n' + body)
        script.chmod(0o755)
        return str(script)

    def test_shell_metacharacters_remain_one_literal_argument_and_logs_hide_urls(self):
        marker = self.root / 'MUST_NOT_EXIST'
        capture = self.root / 'argv.txt'
        url = self.base + '/stream.wav?token=PRIVATE_TOKEN&x=$(touch${IFS}' + str(marker) + ')`id`"'
        self.playlist(url=url)
        fake = self.fake_probe('printf "%s\\n" "$@" > "$TEST_CAPTURE"\nprintf \'{"streams":[{"codec_type":"audio"}]}\'\n')
        result, _ = self.run_cleaner(FFPROBE_PATH=fake, TEST_CAPTURE=str(capture))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(marker.exists())
        self.assertEqual(capture.read_text().splitlines()[-1], url)
        self.assertIn(url, self.output.read_text())
        self.assertNotIn('PRIVATE_TOKEN', result.stdout + result.stderr + self.log.read_text())

    def test_wall_deadline_terminates_probe_even_if_it_ignores_network_timeout(self):
        self.playlist()
        fake = self.fake_probe('trap "" TERM\nexec sleep 60\n')
        result, elapsed = self.run_cleaner(FFPROBE_PATH=fake, PROBE_TIMEOUT_SECONDS='1')
        self.assertEqual(result.returncode, 2)
        self.assertLess(elapsed, 8)
        self.assertIn('timeout', self.log.read_text())

    def test_missing_ffprobe_retains_output(self):
        self.output.write_text('LAST GOOD')
        self.playlist()
        result, _ = self.run_cleaner(FFPROBE_PATH=str(self.root / 'not-installed'))
        self.assertEqual(result.returncode, 1)
        self.assertEqual(self.output.read_text(), 'LAST GOOD')

    def test_invalid_timeout_fails_without_touching_output(self):
        self.output.write_text('LAST GOOD')
        self.playlist()
        for value in ['0', '-1', 'soon', '601']:
            with self.subTest(value=value):
                result, _ = self.run_cleaner(PROBE_TIMEOUT_SECONDS=value)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(self.output.read_text(), 'LAST GOOD')

    def test_unsupported_protocols_are_not_probed(self):
        self.playlist(url='file:///etc/passwd')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 2)
        self.assertFalse(self.output.exists())

    def test_generated_output_in_input_directory_is_not_reprocessed(self):
        self.output = self.inputs / 'previous.m3u'
        self.output.write_text('#EXTM3U\n#EXTINF:-1,Old\n' + self.base + '/stream.wav\n')
        self.playlist(title='New')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('Old', self.output.read_text())
        self.assertIn('New', self.output.read_text())

    def test_hls_input_manifest_is_rejected_without_partial_publish(self):
        self.output.write_text('LAST GOOD')
        self.playlist('a.m3u')
        (self.inputs / 'b.m3u8').write_text('#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXTINF:2,\nsegment.ts\n')
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 2)
        self.assertEqual(self.output.read_text(), 'LAST GOOD')

    def test_output_lock_prevents_overlapping_writes(self):
        import fcntl
        self.output.write_text('LAST GOOD')
        self.playlist()
        with open(str(self.output) + '.lock', 'w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(self.output.read_text(), 'LAST GOOD')

    def test_unwritable_output_target_does_not_publish_partial_file(self):
        self.playlist()
        self.output.mkdir()
        result, _ = self.run_cleaner()
        self.assertEqual(result.returncode, 1)
        self.assertTrue(self.output.is_dir())
        self.assertEqual(list(self.output.parent.glob('.playlist-*')), [])


if __name__ == '__main__':
    unittest.main()
