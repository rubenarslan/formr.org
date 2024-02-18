import argparse
import sys
import pathlib
import json

import cv2

import blind_watermark
import siftwatermark


def embed(args):
    """
    Execute the embedding command with the given arguments.

    :param args: The parsed arguments.
    :return: None
    """
    # Define a standard watermark text if none is given
    if args.watermark_text is None:
        watermark_text = "watermark"
    else:
        watermark_text = args.watermark_text

    image_path = args.image_path
    output_path = args.output_path

    # Try to execute the embedding using the given method, save the image and print results
    try:
        if args.method == "blind":
            watermarked_image, watermark_key = blind_watermark.embed_watermark(image_path, watermark_text)
            watermark_key = str(watermark_key)
        elif args.method == "sift":
            watermarked_image, watermark_key = siftwatermark.embedding(image_path, watermark_text)
            watermark_key = json.dumps(watermark_key)
        else:
            raise ValueError("Unknown method")

        # Define a standard output path if none is given and create the path directory
        image_path = pathlib.Path(image_path)
        if output_path is None:
            output_path = image_path.parent / "watermark" / image_path.name
        else:
            output_path = pathlib.Path(output_path)
        output_path.parent.mkdir(parents=True, exist_ok=True)

        # Write the image to the output path and check if the image was written successfully
        successful = cv2.imwrite(str(output_path), img=watermarked_image)
        if not successful:
            raise IOError(f"Could not write image to {output_path}")

        # Print results to the standard output
        print(str(output_path))
        print(watermark_key)

    except Exception as e:
        # Print an error message and exit with exit code 1 if an exception occurs
        print(e)
        sys.exit(1)

    sys.exit(0)


def extract(args):
    """
    Execute the extraction command with the given arguments.

    :param args: The parsed arguments.
    :return: None
    """
    image_path = args.image_path
    watermark_key = args.watermark_key
    output_path = args.output_path

    # Try to execute the extraction using the given method and print results
    try:
        if args.method == "blind":
            watermark_key = int(watermark_key)
            watermark, _ = blind_watermark.extract_watermark(image_path, watermark_key, output_path)
        elif args.method == "sift":
            watermark_key = json.loads(watermark_key)
            watermark = siftwatermark.extraction(image_path, watermark_key)
        else:
            raise ValueError("Unknown method")

        # Print results to the standard output
        print(watermark)

    except Exception as e:
        # Print an error message and exit with exit code 1 if an exception occurs
        print(e)
        sys.exit(1)

    sys.exit(0)


def main():
    """
    Main entry for the Watermark Command Line Interface (CLI).

    :return: None
    """
    # Create command line parser
    parser = argparse.ArgumentParser(description="Embed and extract watermarks from images.")
    subparsers = parser.add_subparsers(help="commands")

    # Create sub parser for the embedding command
    embed_parser = subparsers.add_parser("embed", help="embed a watermark into an image")
    embed_parser.add_argument("-i", "--image_path", type=str, required=True,
                              help="path to the image to add the watermark to")
    embed_parser.add_argument("-o", "--output_path", type=str, required=False,
                              help="path where to save the watermarked image to")
    embed_parser.add_argument("-w", "--watermark_text", type=str, required=False,
                              help="watermark text to embed in the image")
    embed_parser.add_argument("-m", "--method", type=str, required=True,
                              help="method to use for the embedding")
    embed_parser.set_defaults(func=embed)

    # Create sub parser for the extraction command
    extract_parser = subparsers.add_parser("extract", help="extract a watermark from an image")
    extract_parser.add_argument("-i", "--image_path", type=str, required=True,
                                help="path to the image to extract the watermark from")
    extract_parser.add_argument("-m", "--method", type=str, required=True,
                                help="method to use for the extraction")
    extract_parser.add_argument("-w", "--watermark_key", type=str, required=True,
                                help="method specific watermark key needed for the extraction")
    extract_parser.add_argument("-o", "--output_path", type=str, required=False,
                                help="path where to save the qr code to")
    extract_parser.set_defaults(func=extract)

    # Parse arguments
    args = parser.parse_args()

    # Execute the given command or print help and exit if no command is given
    if hasattr(args, "func"):
        args.func(args)
    else:
        parser.print_help()
        sys.exit(1)


if __name__ == "__main__":
    main()
