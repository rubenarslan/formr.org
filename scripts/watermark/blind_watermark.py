import pathlib
import math

import cv2
import qrcode
import numpy as np

import blind_watermark_core


def embed_watermark(image_path, watermark_text):
    """
    Embed a watermark text into an image.

    The embedding process uses a QR-Code to embed the text as an image and make it more robust.
    The QR-Code image is then embedded using a robust and blind watermark algorithm using DWT, DCT and SVD.

    :param image_path: The path to the image to add the watermark to.
    :param watermark_text: The watermark text to embed.
    :return: The watermarked image, the watermark key needed to extract the watermark.
    """
    # Create image path and check if the file exists
    image_path = pathlib.Path(image_path)
    if not image_path.exists():
        raise FileNotFoundError(f"{image_path} does not exist")

    # Read image and check if the image was read successfully
    image = cv2.imread(str(image_path), flags=cv2.IMREAD_UNCHANGED)
    if image is None:
        raise IOError(f"Could not read image at {image_path}")

    # Define QR-Code with maximum error correction and create it using the watermark text
    # Make the QR-Code as small as possible by setting version=None, box_size=1, border=0 and fit=True
    qr = qrcode.QRCode(version=None, error_correction=qrcode.constants.ERROR_CORRECT_H, box_size=1, border=0)
    qr.add_data(watermark_text)
    qr.make(fit=True)
    qr_code = qr.make_image(fill_color=(0, 0, 0), back_color=(255, 255, 255))

    # Convert the QR-Code to a grayscale CV2 image
    watermark = cv2.cvtColor(np.array(qr_code), cv2.COLOR_RGB2GRAY)

    # Calculate the minimal width and height to store the watermark 4 times in the image
    # Respect the original aspect ratio and block shape used in the embedding
    watermark_size = (watermark.shape[0] + 1) * (watermark.shape[1] + 1) * 4
    min_width = math.sqrt(watermark_size * (image.shape[0] / image.shape[1]))
    min_height = watermark_size / min_width
    min_width = math.ceil(min_width) * 4 * 2
    min_height = math.ceil(min_height) * 4 * 2

    # If the image is smaller than the minimal width and height, resize it to these dimensions
    if image.shape[0] < min_height or image.shape[1] < min_width:
        image = cv2.resize(image, (min_height, min_width), interpolation=cv2.INTER_LINEAR)

    # Embed the watermark into the image
    embedded_image = blind_watermark_core.embed_watermark(image, watermark)

    return embedded_image, watermark.shape[0]


def extract_watermark(image_path, watermark_key, output_path=None):
    """
    Extract a watermark text from an image.

    The extraction process extracts a QR-Code from the image which stores the watermark text.
    The QR-Code image is extracted using an inverse robust and blind watermark algorithm using DWT, DCT and SVD.

    If an output_path is given the extracted QR-Code is saved to this path.

    :param image_path: The path to the image to extract the watermark from.
    :param watermark_key: The watermark key returned by the embedding process.
    :param output_path: The path where to store extracted QR-Code (optional).
    :return: The watermark text, the used output path.
    """
    # Create image path and check if the file exists
    image_path = pathlib.Path(image_path)
    if not image_path.exists():
        raise FileNotFoundError(f"{image_path} does not exist")

    # Read image and check if the image was read successfully
    image = cv2.imread(str(image_path), flags=cv2.IMREAD_UNCHANGED)
    if image is None:
        raise IOError(f"Could not read image at {image_path}")

    # Extract the watermark from the image
    watermark = blind_watermark_core.extract_watermark(image, (watermark_key, watermark_key))

    # Convert the extracted watermark image to a standard QR-Code
    # Therefore convert to BW, add a border of 4 boxes and use a box size of 10
    watermark = np.where(watermark > 127, 255, 0).astype(np.uint8)
    watermark = np.pad(watermark, 4, constant_values=255)
    watermark = np.repeat(watermark, 10, axis=0)
    watermark = np.repeat(watermark, 10, axis=1)

    # If an output path is given, write the QR-Code image to that path
    if output_path is not None:
        output_path = pathlib.Path(output_path)
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path = str(output_path)
        successful = cv2.imwrite(output_path, img=watermark)
        if not successful:
            raise IOError(f"Could not write image to {output_path}")

    # Detect and decode the QR-Code data from the QR-Code image and check if the QR-Code was detected successfully
    detector = cv2.QRCodeDetector()
    data, vertices_array, _ = detector.detectAndDecode(watermark)
    if vertices_array is None:
        raise Exception("Could not detect QR-Code")

    return data, output_path
