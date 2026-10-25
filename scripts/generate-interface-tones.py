"""Create original AOV modem and message tones without sampled recordings.

Requires Python 3 and ffmpeg. Output is written to the chosen directory so
existing deployed files are never overwritten during generation.
"""

import argparse
import math
import random
import struct
import subprocess
import tempfile
import wave
from pathlib import Path

RATE = 22050
SEED = 1997


def save_mp3(path, samples):
    with tempfile.TemporaryDirectory() as temporary:
        wav_path = Path(temporary) / "tone.wav"
        with wave.open(str(wav_path), "wb") as output:
            output.setnchannels(1)
            output.setsampwidth(2)
            output.setframerate(RATE)
            output.writeframes(
                b"".join(struct.pack("<h", int(max(-1, min(1, value)) * 32767)) for value in samples)
            )
        subprocess.run(
            ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(wav_path),
             "-codec:a", "libmp3lame", "-b:a", "96k", str(path)],
            check=True,
        )


def tone(duration, frequencies, volume=0.45, noise=0, start=0, end=None, rng=None):
    count = round(duration * RATE)
    end = end if end is not None else frequencies[0]
    rng = rng or random.Random(SEED)
    for i in range(count):
        t = i / RATE
        ramp = min(1, i / (RATE * 0.015), (count - i) / (RATE * 0.025))
        frequency = frequencies[0] + (end - frequencies[0]) * i / max(1, count)
        signal = math.sin(2 * math.pi * frequency * t)
        for extra in frequencies[1:]:
            signal += math.sin(2 * math.pi * extra * t)
        signal /= len(frequencies)
        if noise:
            signal = signal * (1 - noise) + rng.uniform(-1, 1) * noise
        yield volume * max(0, ramp) * signal


def silence(duration):
    return [0.0] * round(duration * RATE)


def modem():
    rng = random.Random(SEED)
    audio = []
    audio.extend(tone(1.0, [350, 440], 0.27))
    audio.extend(silence(0.2))
    for low, high in [(697, 1209), (770, 1336), (852, 1477), (697, 1336),
                      (770, 1209), (852, 1336), (697, 1477), (770, 1477)]:
        audio.extend(tone(0.16, [low, high], 0.32))
        audio.extend(silence(0.065))
    audio.extend(silence(0.45))
    for _ in range(2):
        audio.extend(tone(1.3, [440, 480], 0.19))
        audio.extend(silence(0.35))
    audio.extend(tone(0.7, [2100], 0.32, end=1300))
    audio.extend(silence(0.15))
    audio.extend(tone(1.5, [1300, 1500], 0.25, noise=0.18, rng=rng))
    remaining = 19 * RATE - len(audio)
    for n in range(remaining):
        t = n / RATE
        progress = n / max(1, remaining)
        carrier = 980 + 1000 * math.sin(2 * math.pi * (1.1 + 1.3 * progress) * t)
        buzz = math.sin(2 * math.pi * carrier * t) * 0.36
        hiss = rng.uniform(-1, 1) * (0.16 + 0.1 * progress)
        pulse = 0.4 + 0.6 * abs(math.sin(2 * math.pi * 3.7 * t))
        fade = min(1, (remaining - n) / (RATE * 0.25))
        audio.append((buzz + hiss) * pulse * fade)
    return audio


def chime(notes):
    audio = []
    for frequency, duration in notes:
        audio.extend(tone(duration, [frequency, frequency * 2], 0.22))
        audio.extend(silence(0.035))
    return audio


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    save_mp3(args.output / "dialup.mp3", modem())
    save_mp3(args.output / "message.mp3", chime([(660, 0.17), (880, 0.25)]))
    save_mp3(args.output / "sent.mp3", chime([(880, 0.12), (660, 0.19)]))


if __name__ == "__main__":
    main()
