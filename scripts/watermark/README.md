# Image Watermarking - Python

With this tool, watermarks that are not visible to humans can be embedded or extracted from images. This can be used,
for example, to
prove ownership of the image.

A user-friendly Command Line Interface (CLI) is available for use.

The tool currently offers two methods for embedding/extracting:

* Blind Watermark
* SIFT Watermark

## Setup

Make sure that [Python 3.10](https://www.python.org/downloads/) or newer is installed.

Run the following commands:

```bash
python3 -m venv venv
```

```bash
source venv/bin/activate
```

```bash
python3 -m pip install --upgrade pip
```

```bash
python3 -m pip install -r requirements.txt
```

## Embed watermark

To embed a watermark, run the following command:

Blind watermark:

```bash
python3 main.py embed -i path/to/image.png -o path/to/output.png -w "watermark" -m "blind"
```

SIFT watermark:

```bash
python3 main.py embed -i path/to/image.png -o path/to/output.png -w "watermark" -m "sift"
```

If successful, the watermarked image will be saved at the output path and the output path and a watermark key will be
printed. This watermark key is method-specific and needed for the extraction.

## Extract watermark

To extract a watermark, run the following command:

Blind watermark:

```bash
python3 main.py extract -i path/to/image.png -m "blind" -w "21" -o path/to/output.png
```

SIFT watermark:

```bash
python3 main.py extract -i path/to/image.png -m "sift" -w "[...]"
```

Use the method-specific watermark key as -w. If successful, the detected watermark(s) will be printed.

## Help

To print the help page, run the following command:

```bash
 python3 main.py -h
```