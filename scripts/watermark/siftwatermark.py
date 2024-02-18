import cv2 as cv
import numpy as np
import pywt


# based on:
# https://link.springer.com/article/10.1007/s11042-022-12738-x
# https://www.mdpi.com/2227-7390/11/7/1730#B45-mathematics-11-01730
# https://www.sciencedirect.com/science/article/pii/S0030402614000345
# https://www.sciencedirect.com/science/article/pii/S0096300308002865
# https://ieeexplore.ieee.org/abstract/document/8513859
# projective transformation: https://www.youtube.com/watch?v=2BIzmFD_pRQ

def embedding(image, watermark, region_diameter=64, alpha=None):
    """
    Embeds a watermark in the given image.
    :param image: The image to be watermarked.
    :param watermark: A string of 1 to 31 common chars (.,/abc123 etc.), e.g. 'uni-muenster.de/formr'.
    :param alpha: Optional: Embedding strength factor. If not provided, this will be calculated automatically.
    :param region_diameter: Diameter of the embedding region around a keypoint. Must be divisible by 4.
    :return: The watermarked image and an array containing the images length and width and coordinates of keypoints.

    Note that the maximum capacity of the watermark is depending on the diameter of the embedding region, which by
    default is 64x64 pixel, resulting in a capacity of 220 bits.
    """
    # Get the ascii values of the watermark chars
    ascii_values = watermark.encode('ascii')
    # Concatenate the ascii-representation (7 bits per char) of the watermark chars
    bin_watermark = ''.join(f'{x:07b}' for x in ascii_values)

    # Check whether the watermark fits the feature region (36 because of window_size)
    if len(bin_watermark) > (pow(region_diameter // 4, 2) - 36):
        raise ValueError('Der Wasserzeichentext darf maximal 31 Zeichen lang sein.')

    img = cv.imread(image)

    # Convert image to YCrCb and extract the Y-Channel to operate on
    gray = cv.cvtColor(img, cv.COLOR_BGR2YCrCb)
    ychannel, crchannel, cbchannel = cv.split(gray)

    # Does the image have the right size?
    height = np.size(img, 0)
    width = np.size(img, 1)

    if height < 150:
        raise ValueError('Das Bild muss größer als 150x150 Pixel sein.')
    if width < 150:
        raise ValueError('Das Bild muss größer als 150x150 Pixel sein.')

    # Create Wavelet object (Haar Wavelet or Daubechies Wavelet (e.g. db3))
    wavelet = pywt.Wavelet('haar')

    # Create a mask for sift algorithm
    mask = np.ones((height, width), dtype=np.uint8)
    distanceToEdge = (region_diameter // 2) + 18

    # 0 0 0 0
    # 0 1 1 0
    # 0 0 0 0

    mask[: distanceToEdge, :] = 0
    mask[height - distanceToEdge:, :] = 0
    mask[distanceToEdge: height - distanceToEdge, : distanceToEdge] = 0
    mask[distanceToEdge: height - distanceToEdge, width - distanceToEdge: width] = 0

    # Detect keypoints with sift. It searches for keypoints in the area specified by the mask
    sift = cv.SIFT.create()
    kp = sift.detect(ychannel, mask)

    # Sort keypoints by response value in descending order
    kp_sorted = sorted(kp, key=lambda x: x.response, reverse=True)

    # Create marker_matrix to detect non-overlapping regions of the strongest keypoints
    marker_matrix = np.zeros((height, width), dtype=np.uint8)

    # Region size is 64x64 by default, thus 32 in each direction around the keypoint
    region_radius = region_diameter // 2
    # Size of the sub matrices 4x4
    sub_size = 4
    # Target number of regions to embed
    number_of_regions = 5
    # Matrices per row and col
    m_p_r_c = region_diameter // sub_size
    # Size of an empty window around the keypoint
    window_size = 24
    assert (window_size // sub_size) % 2 == 0, "Modify the window_size"
    # Rows and columns to be skipped
    empty_area = (m_p_r_c - (window_size // sub_size)) // 2

    # Fill marker_matrix with 1's in a 64x64 window around the keypoints of kp_sorted to get the first five strongest
    # non-overlapping keypoints (hamming window)
    keypoints_final = []
    i = 0
    j = 0
    while i < number_of_regions and j < len(kp_sorted):
        x = int(round(kp_sorted[j].pt[0]))
        y = int(round(kp_sorted[j].pt[1]))

        if np.max(marker_matrix[y - region_radius: y + region_radius, x - region_radius: x + region_radius]) == 0:
            marker_matrix[y - region_radius: y + region_radius, x - region_radius: x + region_radius] = 1
            keypoints_final.append(kp_sorted[j])
            i += 1
        j += 1

    "Embedding the watermark!"

    # Number of 4x4 matrices per row/column in a feature region
    k = region_radius // 2
    
    # Check whether alpha should be calculated automatically or not
    automated = True
    if alpha is not None:
        automated = False

    for i in range(len(keypoints_final)):
        x = int(round(keypoints_final[i].pt[0]))
        y = int(round(keypoints_final[i].pt[1]))

        # Apply Discrete Wavelet Transformation (DWT) to the feature region
        coeffs = pywt.dwt2(ychannel[y - region_radius: y + region_radius, x - region_radius: x + region_radius],
                           wavelet)
        cA, (cH, cV, cD) = coeffs
        # Sum up the low-frequency sub-band LL
        sum_LL = np.sum(np.abs(cA))
        # Sum up the high-frequency sub-band HH (maybe include HL, LH ?)
        sum_HH = np.sum(np.abs(cD))
        # Calculate medium brightness value of the feature region
        mean_ychannel = np.mean(ychannel[y - region_radius: y + region_radius, x - region_radius: x + region_radius])

        # Calculate a good embedding strength factor alpha (there is a need of improvement)
        if automated:
            if sum_HH < 1200:
                alpha = 0.008
            if 1200 < sum_HH < 2400:
                alpha = 0.01
            if sum_LL > 300000 or mean_ychannel > 130:
                alpha = 0.008
            if mean_ychannel < 70:
                alpha = 0.015
            if 2400 < sum_HH < 5000:
                alpha = 0.015
                if sum_LL > 300000 or mean_ychannel > 120:
                    alpha = 0.01
                if mean_ychannel < 80:
                    alpha = 0.02
            if 5000 < sum_HH < 10000:
                alpha = 0.02
                if sum_LL > 400000 or mean_ychannel > 160:
                    alpha = 0.01
            if sum_HH > 10000:
                alpha = 0.03

        # Index of binary watermark string
        j = 0
        for ro in range(k):

            for co in range(k):
                # 24x24 empty window around the keypoint to not distract the feature value calculation when extracting
                if (empty_area - 1 < ro < 2 * empty_area + 1) and (empty_area - 1 < co < 2 * empty_area + 1):
                    continue

                row = y - region_radius + (ro * sub_size)
                col = x - region_radius + (co * sub_size)

                # Singular value decomposition (SVD) of each 4x4 (sub_size) block
                U, S, Vh = np.linalg.svd(ychannel[row: row + sub_size, col: col + sub_size], full_matrices=False)

                # Embeds the watermark bits in the U matrix (these specific coefficients can resist JPEG compression)
                if bin_watermark[j] == '0':
                    U[1][0] = max(U[1][0], U[2][0]) + alpha
                    U[2][0] = min(U[1][0], U[2][0]) - alpha
                else:
                    U[1][0] = min(U[1][0], U[2][0]) - alpha
                    U[2][0] = max(U[1][0], U[2][0]) + alpha

                # Optimization: Also modify the coefficients in the first row of the Vh matrix

                # This matrix will replace its corresponding part in the ychannel
                watermarked_square = U @ np.diag(S) @ Vh
                # Clip the values to avoid overflows
                np.clip(watermarked_square, 0, 255, watermarked_square)

                # Embeds the watermark in the ychannel
                ychannel[row: row + sub_size, col: col + sub_size] = watermarked_square

                j += 1
                if j == len(bin_watermark):
                    break
            if j == len(bin_watermark):
                j = 0
                break

    # An optimization could include the use of SSIM (structural similarity index) between original region and
    # watermarked region to select only those with lowest visual distortion

    # Store the coordinates of the keypoints used for embedding and height/width of the image
    information = []
    for a in range(len(keypoints_final)):
        information.append((keypoints_final[a].pt[0], keypoints_final[a].pt[1]))
    information.append((height, width))
    # Number of modified regions and length of binary watermark are stored too
    information.append((len(keypoints_final), len(bin_watermark)))

    # Merge the watermarked channel with the others and reconstruct the image
    watermark_ycrcb = cv.merge((ychannel, crchannel, cbchannel))
    watermark_img = cv.cvtColor(watermark_ycrcb, cv.COLOR_YCrCb2BGR)

    return watermark_img, information


def projection_transformation(dist_img, v, vo):
    """
    Projective Transformation is needed if the watermarked image is e.g. not downloaded but captured with a camera from
    a different angle. It transforms the watermarked image to its original shape.
    :param dist_img: The distorted image showing the watermarked image from a different angle.
    :param v: A 4x2 matrix containing the vertex x- and y-coordinates of the watermarked image inside the dist_img.
    :param vo: A 4x2 matrix containing the vertex x- and y-coordinates of the original watermarked image.
    :return: The distorted watermarked image but with its original shape.

    Note that v and vo have to follow the same order of vertices:

    (x0,y0)         ...         (x1,y1)
        .                           .
        .                           .
    (x3,y3)         ...         (x2,y2)

    """
    # First the unknown coefficients for the projection transformation matrix have to be determined (a)
    pj_tr = np.array([[vo[0][0], vo[0][1], 1, 0, 0, 0, -vo[0][0] * v[0][0], -vo[0][1] * v[0][0]],
                      [0, 0, 0, vo[0][0], vo[0][1], 1, -vo[0][0] * v[0][1], -vo[0][1] * v[0][1]],
                      [vo[1][0], vo[1][1], 1, 0, 0, 0, -vo[1][0] * v[1][0], -vo[1][1] * v[1][0]],
                      [0, 0, 0, vo[1][0], vo[1][1], 1, -vo[1][0] * v[1][1], -vo[1][1] * v[1][1]],
                      [vo[2][0], vo[2][1], 1, 0, 0, 0, -vo[2][0] * v[2][0], -vo[2][1] * v[2][0]],
                      [0, 0, 0, vo[2][0], vo[2][1], 1, -vo[2][0] * v[2][1], -vo[2][1] * v[2][1]],
                      [vo[3][0], vo[3][1], 1, 0, 0, 0, -vo[3][0] * v[3][0], -vo[3][1] * v[3][0]],
                      [0, 0, 0, vo[3][0], vo[3][1], 1, -vo[3][0] * v[3][1], -vo[3][1] * v[3][1]]])
    # Ordinate values (b)
    val = np.array([v[0][0], v[0][1], v[1][0], v[1][1], v[2][0], v[2][1], v[3][0], v[3][1]])

    # Solve the following equation: ax = b
    solution = np.linalg.solve(pj_tr, val)
    # Reshape the output array to get the 3x3 transformation matrix
    solution = np.append(solution, 1).reshape((3, 3))

    img = cv.imread(dist_img)

    # Perform projection transformation on the distorted image
    transformed_img = cv.warpPerspective(img, solution, (vo[2][0], vo[2][1]), 0, flags=cv.WARP_INVERSE_MAP)

    return transformed_img


def extraction(image, information, region_diameter=64, distorted=False, coords=None):
    """
    Extracts the watermark
    :param image: Image from which the watermark shall be extracted.
    :param information: A list returned by the embedding method. Contains the coordinates of the keypoints and
    other information. However, the coordinates aren't needed but can serve as last resort.
    :param region_diameter: Has to be the same size used in embedding method.
    :param distorted: (optional) If the image is distorted (e.g. captured by a camera from a different angle), set this
    to true. Default is False.
    :param coords: (optional) Only needed if distorted=True. 4x2 array containing the vertex coordinates of the
    distorted image. (see projection_transformation method)
    :return: Possible watermark strings.
    """
    # Optimization: Use Wiener filtering method first to denoise the image

    img = cv.imread(image)
    len_list = len(information)

    # Get the width and height of the original watermarked image.
    width = information[len_list - 2][1]
    height = information[len_list - 2][0]

    # Resize the image if necessary
    if np.shape(img) != information[len_list - 2]:
        img = cv.resize(img, (width, height))

    # Length of the watermark.
    len_wm = information[len_list - 1][1]

    # Number of used keypoints
    kp_numb = information[len_list - 1][0]

    # Number of extracting regions is doubled to enhance error-tolerant rate
    ext_reg_numb = 2 * kp_numb

    # If the watermarked image is distorted, perform projection_transformation first
    if distorted:
        # Reconstruct the original vertex coordinates
        original_vertices = np.array([[0, 0],
                                      [width, 0],
                                      [width, height],
                                      [0, height]])
        # Projection Transformation provides the geometric corrected image
        img = projection_transformation(image, coords, original_vertices)

        ext_reg_numb += 5

    # Make sure the image to operate on has the original sizes
    if np.shape(img)[0] != height:
        raise ValueError(
            'Etwas ist schiefgegangen: Die Bildgröße stimmt nicht mit der des ursprünglichen Bildes überein.')
    if np.shape(img)[1] != width:
        raise ValueError(
            'Etwas ist schiefgegangen: Die Bildgröße stimmt nicht mit der des ursprünglichen Bildes überein.')

    # Convert image to YCrCb and extract the Y-Channel to operate on
    gray = cv.cvtColor(img, cv.COLOR_BGR2YCrCb)
    ychannel, crchannel, cbchannel = cv.split(gray)

    "Calculate keypoints with SIFT"

    # Create a mask for sift algorithm
    mask = np.ones((height, width), dtype=np.uint8)
    distanceToEdge = (region_diameter // 2) + 1

    # 0 0 0 0
    # 0 1 1 0
    # 0 0 0 0

    mask[: distanceToEdge, :] = 0
    mask[height - distanceToEdge:, :] = 0
    mask[distanceToEdge: height - distanceToEdge, : distanceToEdge] = 0
    mask[distanceToEdge: height - distanceToEdge, width - distanceToEdge: width] = 0

    # Detect keypoints with sift
    sift = cv.SIFT.create()
    kp = sift.detect(ychannel, mask)

    # Sort keypoints by response value in descending order
    kp_sorted = sorted(kp, key=lambda z: z.response, reverse=True)

    # If too few keypoints were found, use them anyway
    if len(kp_sorted) < ext_reg_numb:
        ext_reg_numb = len(kp_sorted)

    # If two keypoints have the same coordinates (they differ in other ways), one of them will be deleted.
    restart = True
    while restart:
        restart = False
        for i in range(ext_reg_numb):
            for j in range(i + 1, ext_reg_numb):
                a = kp_sorted[i].pt[0]
                b = kp_sorted[j].pt[0]
                if abs(a - b) <= max(1e-06 * max(abs(a), abs(b)), 0.0):
                    kp_sorted.pop(j)
                    restart = True
                    break

    possible_watermarks = []
    sub_size = 4
    region_radius = region_diameter // 2

    # Matrices per row and col
    m_p_r_c = region_diameter // sub_size
    # Size of the empty window around the keypoint
    window_size = 24
    assert (window_size // sub_size) % 2 == 0, "Modify the window_size"
    # Rows and columns to be skipped
    empty_area = (m_p_r_c - (window_size // sub_size)) // 2
    possible_wm = ''
    for i in range(kp_numb):
        x = round(kp_sorted[i].pt[0])
        y = round(kp_sorted[i].pt[1])
        # If the watermark cannot be extracted, try using the original coordinates
        # x = round(information[i][0])
        # y = round(information[i][1])
        # The coordinates of keypoints can slightly change. Therefore, consider its eight neighbors
        neighbors = [[x - 1, y - 1], [x, y - 1], [x + 1, y - 1],
                     [x - 1, y], [x, y], [x + 1, y],
                     [x - 1, y + 1], [x, y + 1], [x + 1, y + 1]]

        for m in range(len(neighbors)):
            x = neighbors[m][0]
            y = neighbors[m][1]

            # Index of binary watermark string
            j = 0
            for ro in range(m_p_r_c):

                for co in range(m_p_r_c):
                    # 24x24 empty window around the keypoint to not distract the feature value calculation
                    if (empty_area - 1 < ro < 2 * empty_area + 1) and (empty_area - 1 < co < 2 * empty_area + 1):
                        continue

                    row = y - region_radius + (ro * sub_size)
                    col = x - region_radius + (co * sub_size)

                    # Singular value decomposition (SVD) of each 4x4 (sub_size) block
                    Un, Sn, Vnh = np.linalg.svd(ychannel[row: row + sub_size, col: col + sub_size], full_matrices=False)

                    # Since these coefficients can resist JPEG compression, it is likely that they didn't change much
                    if Un[1][0] >= Un[2][0]:
                        watermark_bit = '0'
                    else:
                        watermark_bit = '1'

                    possible_wm += watermark_bit
                    j += 1

                    if j == len_wm:
                        break
                if j == len_wm:
                    j = 0
                    break

            # Get a list of all the possible watermark sequences
            possible_watermarks.append(possible_wm)
            possible_wm = ''

    # Calculate hamming distance between all possible watermarks. Those with the highest similarity are selected.
    possible_watermarks_final = []

    for i in range(len(possible_watermarks) - 1):
        for j in range(i + 1, len(possible_watermarks) - 1):
            curr = sum(c1 != c2 for c1, c2 in zip(possible_watermarks[i], possible_watermarks[j]))
            # Hamming distance threshold is set to 10. If you are struggling to recover a watermark at all, maybe
            # adjust it to 20 or higher.
            if curr < 10:
                w1 = possible_watermarks[i]
                w2 = possible_watermarks[j]
                possible_watermarks_final.append(w1)
                possible_watermarks_final.append(w2)

    watermarks = []
    # Remove duplicates in the list of all possible watermarks
    possible_watermarks_final = list(set(possible_watermarks_final))

    # Convert the binary data back to strings
    for j in possible_watermarks_final:
        bin_blocks = [j[i:i + 7] for i in range(0, len_wm, 7)]
        watermark_rec = ''.join(chr(int(b, 2)) for b in bin_blocks)
        # print(watermark_rec)
        watermarks.append(watermark_rec)

    return watermarks
