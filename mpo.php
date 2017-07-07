<?php
//default values
$filename_out = "out.mpo";
$baseline_length = 77;
$NUMBER_OF_IMAGES = 2;

//CLI options
$options = getopt("l:r:o:");
$filename_left = $options["l"];
$filename_right = $options["r"];
$filename_out = $options["o"];

//Constants
define("MARKER_APP0", chr(0xff).chr(0xe0));
define("MARKER_APP1", chr(0xff).chr(0xe1));
define("MARKER_APP2", chr(0xff).chr(0xe2));
define("MARKER_SIZE", 2);
define("LEN_SIZE", 2);

//utils functions
function unpack_hexstr($s){
    return unpack('H*', $s)[1];
}

function unpack_hexstr_to_decint($s){
    $s_hex = unpack_hexstr($s);
    return intval($s_hex, 16);
}

/**
   given a string, return an array containing the hexadecimal representation
   with one Byte per entry
 */
function to_chunk($s){
    $step = 2;  //2 hexadecimal characters per Byte
    $chunk= array();
    for($i=$step; $i<=$step*4; $i+=$step){
        $chunk_str = substr($s, -$i, $step);
        $chunk_hex = intval($chunk_str, 16);
        array_push ($chunk, $chunk_hex);
    }
    return $chunk;
}

/**
   locate APP0 APP1 APP2 position and length
 */
function read_meta($img_data){
    $meta = array();
    $APP0_pos = strpos($img_data, MARKER_APP0);
    if($APP0_pos){
        $pos = $APP0_pos;
        $len_str = substr($img_data, $pos+MARKER_SIZE, LEN_SIZE);
        $len = unpack_hexstr_to_decint($len_str);
        $meta['APP0']['pos'] = $pos;
        $meta['APP0']['len'] = $len;
    }
    $APP1_pos = strpos($img_data, MARKER_APP1, $pos);
    if($APP1_pos){
        $pos = $APP1_pos;
        $len_str = substr($img_data, $pos+MARKER_SIZE, LEN_SIZE);
        $len = unpack_hexstr_to_decint($len_str);
        $meta['APP1']['pos'] = $pos;
        $meta['APP1']['len'] = $len;
    }

    $APP2_pos = strpos($img_data, MARKER_APP2, $pos);
    if($APP2_pos){
        $pos = $APP2_pos;
        $len_str = substr($img_data, $pos+MARKER_SIZE, LEN_SIZE);
        $len = unpack_hexstr_to_decint($len_str);
        $meta['APP2']['pos'] = $pos;
        $meta['APP2']['len'] = $len;
    }
    return $meta;
}

/**
   search a suitable location for APP2, which is:
 * After APP1, if no APP1 found, after APP0 if no APP1 or APP0.
 * After SOI if no APP0 and no APP1.
 * If app2 is present, erase it, including APP marker
   @return the position where APP2 should be created
 */
function set_APP2(&$img_data){
    $meta = read_meta($img_data);
    $APP2_POS;
    if(array_key_exists('APP2', $meta)){
        //erase APP2 so we can replace it with our data (UNTESTED)
        $APP2_POS = $meta['APP2']['pos'];
        $img_data = substr_replace($img_data,
                                   '',
                                   $APP2_POS,
                                   $meta['APP2']['len']);
    }
    else{
        if(array_key_exists('APP0', $meta)){
            $APP2_POS =
                $meta['APP0']['pos'] +
                $meta['APP0']['len'] +
                1;
        }
        else if(array_key_exists('APP1', $meta)){
            $APP2_POS =
                $meta['APP1']['pos'] +
                $meta['APP1']['len'] +
                1;
        }
        else{
            $APP2_POS = MARKER_SIZE;   //SOI marker size
        }
    }

    return $APP2_POS;
}

////////////////////////////////////////////////////////////////////////////////
// get file content and search a suitable location where to insert APP2.
$img_data_left = file_get_contents($filename_left);
$img_data_right = file_get_contents($filename_right);
$file_size_left = strlen($img_data_left);
$file_size_right = strlen($img_data_right);

$APP2_POS_LEFT = set_APP2($img_data_left);
$APP2_POS_RIGHT = set_APP2($img_data_right);

//Size of the segments
$APP2_size_left = 158 + MARKER_SIZE;
$APP2_size_right = 96 + MARKER_SIZE;

////////////////////////////////////////////////////////////////////////////////
// MP EXTENSION (5.2)

//// MP FORMAT IDENTIFIER (5.2.1)
//A Null-Terminated Identifier in ASCII: MPF\0
$MP_FORMAT_IDENTIFIER = pack("C*", 0x4D, 0x50, 0x46, 0x00);
$MP_FORMAT_IDENTIFIER_SIZE = strlen($MP_FORMAT_IDENTIFIER);

////MP HEADER (5.2.2)
//the MP HEADER is composed of the MP_ENDIAN and the OFFSET_TO_FIRST_IFD

//////MP_ENDIAN (5.2.2.1)
//we are using LITTLE ENDIANESS: Less Significative Bits first
$MP_ENDIAN = pack("C*", 0x49, 0x49, 0x2A, 0x00);

////////OFFSET_TO_FIRST_IFD (5.2.2.2)
//offset of the first IFD. It is at the next Byte (\0x08)
$OFFSET_TO_FIRST_IFD = pack("C*", 0x08, 0x00, 0x00, 0x00);

////////////////////////////////////////////////////////////////////////////////
////MP INDEX IFD (5.2.3)
//for the first individual image only. Each field is introduced by a tag.
//count the number of fields to be declared (Version, Number Of Images, MP Entry)
$MPI_COUNT = pack("C*", 0x03, 0x00);

//Version
$MPI_VERSION = pack("C*",
                    0x00, //Tag
                    0xb0,
                    0x07, //Type (undefined)
                    0x00,
                    0x04, //Length of 4 ASCII CHARS
                    0x00,
                    0x00,
                    0x00,
                    0x30, //Version Number 0100 in ASCII
                    0x31,
                    0x30,
                    0x30);


//NUMBER OF IMAGES (5.2.3.2)
$hex_number_of_images = sprintf('%x', $NUMBER_OF_IMAGES);
$MPI_NUMBER_OF_IMAGES = pack("C*",
                             0x01,   //Tag
                             0xb0,
                             0x04,   //Type: Long
                             0x00,
                             0x01,   //Count
                             0x00,
                             0x00,
                             0x00,
                             $hex_number_of_images, //Number of images
                             0x00,
                             0x00,
                             0x00);

//OFFSET Of MP Entries values
$OFFSET_TO_MP_ENTRIES =
    strlen($MP_ENDIAN) +
    strlen($OFFSET_TO_FIRST_IFD) +
    strlen($MPI_COUNT) +
    strlen($MPI_VERSION) +
    strlen($MPI_NUMBER_OF_IMAGES) +
    12 +        //MP ENTRY SIZE (declared after)
    4;          //Offset of the next IFD (declared after)
$mpe_tag_count = 16 * $NUMBER_OF_IMAGES;
$MPE_TAG = pack("C*",
                0x02, //TAG
                0xb0,
                0x07, //Type
                0x00,
                $mpe_tag_count,       //Count
                0x00,
                0x00,
                0x00,
                $OFFSET_TO_MP_ENTRIES,   // 0x46 Offset where are the MPEntries values
                0x00,
                0x00,
                0x00);

//OFFSET OF NEXT IFD
// Offset Details:
// IFD of 16 Bytes per Image:  n * 16 = 32 Bytes. given n = 2 Images
// + Offset of 50 Bytes
// TOTAL of 82 Bytes <=> \0x52  TODO
$next_ifd_offset_value = 16 * $NUMBER_OF_IMAGES + $OFFSET_TO_MP_ENTRIES;
$next_ifd_offset_value_hex = 0x52;//intval($next_ifd_offset_value, 16);
$OFFSET_NEXT_IFD = pack("C*",
                        $next_ifd_offset_value,  //@0x4a
                        0x00,
                        0x00,
                        0x00);

//End of the MPIndex IFD
////////////////////////////////////////////////////////////////////////////////////
//////MP ENTRY: one per image (5.2.3.3)
// OFFSET OF ENDIANESS TAG FROM SOI: 1C
// SIZE OF FILE 1 = original file size + APP2 size
// FILE 2 TO ENDIANESS OFFSET = SIZE OF FILE 1 - OFFSET OF ENDIANESS TAG FROM SOI
//the endianess tag follow the FID offset
$ENDIANESS_TAG_OFFSET =
    $APP2_POS_LEFT +
    MARKER_SIZE +
    $MP_FORMAT_IDENTIFIER_SIZE +
    strlen($MP_ENDIAN) +
    strlen($OFFSET_TO_FIRST_IFD);


//need the file size with the new APP2 segment size and some offsets
$file_size_left_dec = $file_size_left + $APP2_size_left;
$file_size_left_hex = sprintf('%08x', $file_size_left_dec);
$file_size_right_hex = sprintf('%08x', $file_size_right + $APP2_size_right);
$file_data_offset_hex = sprintf('%08x',
                                $file_size_left_dec -
                                $ENDIANESS_TAG_OFFSET);

////MPI VALUES
$file_size_chunk = to_chunk($file_size_left_hex);

//Individual Image Attributes (5.2.3.3.1) (Figure 8)
$MPI_VALUES = pack("C*",
                   0x02,     //Type Code (24 bits) (Table 4) (MultiFrameDisparity) @0x4e
                   0x00,
                   0x02,
                   0b10000000,    //3bits:Image Date format, 2 bits:reserved, 3 bits:flags
                   $file_size_chunk[0], //Individual Image Size (5,2,3,3,2) Big Endian @0x52
                   $file_size_chunk[1],
                   $file_size_chunk[2],
                   $file_size_chunk[3],
                   0x00,            //Individual Image Data Offset (5,2,3,3,3) Must be NULL
                   0x00,
                   0x00,
                   0x00,
                   0x00,            //Independent Image Entry Number 1 (5,2,3,3,4)
                   0x00,
                   0x00,            //Independent Image Entry Number 2
                   0x00);

$file_size_chunk= array(0xe8, 0x28, 0x00, 0x00);//to_chunk($file_size_right_hex);
$file_offset_chunk = array(0xbe, 0x28, 0x00, 0x00);//to_chunk($file_data_offset_hex);

//Individual Image Attributes (5.2.3.3.1) (Figure 8)
$MPI_VALUES_B = pack("C*",
                     0x02,             //Type Code (24 bits) (Table 4) (MultiFrameDisparity)
                     0x00,
                     0x02,
                     0b00000000,    //3bits:Image Date format, 2 bits:reserved, 3 bits:flags
                     $file_size_chunk[0], //Individual Image Size (5,2,3,3,2) Big Endian @0x62
                     $file_size_chunk[1],
                     $file_size_chunk[2],
                     $file_size_chunk[3],
                     $file_offset_chunk[0],   //Individual Image Data Offset (5,2,3,3,3)
                     $file_offset_chunk[1],
                     $file_offset_chunk[2],
                     $file_offset_chunk[3],
                     0x00,                   //Independent Image Entry Number 1 (5,2,3,3,4)
                     0x00,
                     0x00,                   //Independent Image Entry Number 2
                     0x00);

////////////////////////////////////////////////////////////////////////////////////
//Start of MPAttributes IFD (5.2.4)
//count the number of fields to be declared
$MPA_COUNT = pack("C*", 0x04, 0x00);

//MP Individual Image Number (5.2.4.2)
$MPA_INDIVIDUAL_IMAGE_NUMBER = pack("C*",
                                    0x01, //Tag
                                    0xb1,
                                    0x04, //Type
                                    0x00,
                                    0x01, //Count
                                    0x00,
                                    0x00,
                                    0x00,
                                    0x01, //Value
                                    0x00,
                                    0x00,
                                    0x00);

//BASE VIEWPOINT NUMBER (5.2.4.5)  @0x2918
$MPA_BASE_VIEWPOINT_NUMBER = pack("C*",
                                  0x04, //Tag
                                  0xb2,
                                  0x04, //Type
                                  0x00,
                                  0x01, //Count
                                  0x00,
                                  0x00,
                                  0x00,
                                  0x01, //Value
                                  0x00,
                                  0x00,
                                  0x00);

//MPA Convergence Angle (5.2.4.6)
$MPA_CONVERGENCE_ANGLE = pack("C*",
                              0x05, //Tag
                              0xb2,
                              0x0a, //Type: SRATIONAL
                              0x00,
                              0x01, //Count
                              0x00,
                              0x00,
                              0x00,
                              0x88, //Offset Value
                              0x00,
                              0x00,
                              0x00);

//MP Baseline Length (5.2.4.7)
$MPA_BASELINE_LENGTH = pack("C*",
                            0x06, //Tag
                            0xb2,
                            0x05, //Type: RATIONAL
                            0x00,
                            0x01, //Count
                            0x00,
                            0x00,
                            0x00,
                            0x90, //offset value
                            0x00,
                            0x00,
                            0x00);

$OFFSET_NEXT_IFD_NULL = pack("C*",
                             0x00,
                             0x00,
                             0x00,
                             0x00);

//convert the baseline value into hex and array of byte
$baseline_length_hex = sprintf("%08x", $baseline_length);
$baseline_length_chunk = to_chunk($baseline_length_hex);

//MP ATTRIBUT VALUES IFD
$MPA_VALUES = pack("C*",
                   0x00,                       //Convergence angle (5,2,4,6)
                   0x00,
                   0x00,
                   0x00,
                   0x01,
                   0x00,
                   0x00,
                   0x00,
                   $baseline_length_chunk[0], //Baseline length (5,2,4,7)
                   $baseline_length_chunk[1],
                   $baseline_length_chunk[2],
                   $baseline_length_chunk[3],
                   0xe8,
                   0x03,
                   0x00,
                   0x00);

///////////////////////////////////////////////////////////////////////////////
//data to be inserted in APP2 Segments of the right image
//If a record in the  first image fits exacly for the second image, this record
//will be used in second image record.
//In other terms: only differant records from the first image record will be created.
//will be using the MPI version tag of the first image as the MPA version tag for the second image.

$MPA_COUNT_B = pack("C*", 0x05, 0x00);   //@0x28fe

//MP Individual Image Number (5.2.4.2)
$MPA_INDIVIDUAL_IMAGE_NUMBER_B = pack("C*",
                                      0x01, //Tag
                                      0xb1,
                                      0x04, //Type
                                      0x00,
                                      0x01, //Count
                                      0x00,
                                      0x00,
                                      0x00,
                                      0x02, //VALUE
                                      0x00,
                                      0x00,
                                      0x00);

//MPA Convergence Angle (5.2.4.6) @0x2924
$MPA_CONVERGENCE_ANGLE_B = pack("C*",
                                0x05, //Tag
                                0xb2,
                                0x0a, //Type: SRATIONAL
                                0x00,
                                0x01, //Count
                                0x00,
                                0x00,
                                0x00,
                                0x4a, //Offset Value
                                0x00,
                                0x00,
                                0x00);

//MP Baseline Length (5.2.4.7)
$MPA_BASELINE_LENGTH_B = pack("C*",
                              0x06, //Tag
                              0xb2,
                              0x05, //Type: RATIONAL
                              0x00,
                              0x01, //Count
                              0x00,
                              0x00,
                              0x00,
                              0x52, //offset value
                              0x00,
                              0x00,
                              0x00);

////////////////////////////////////////////////////////////////////////////////
//Insert binary data into the left image data in APP2
$segdata_left =
    MARKER_APP2.
    chr(0x00).chr(0x9e).               //lenght of APP2
    $MP_FORMAT_IDENTIFIER.
    $MP_ENDIAN.
    $OFFSET_TO_FIRST_IFD.
    $MPI_COUNT.
    $MPI_VERSION.
    $MPI_NUMBER_OF_IMAGES.
    $MPE_TAG.
    $OFFSET_NEXT_IFD.
    $MPI_VALUES.                       //one mpi value per image
    $MPI_VALUES_B.
    $MPA_COUNT.
    $MPA_INDIVIDUAL_IMAGE_NUMBER.
    $MPA_BASE_VIEWPOINT_NUMBER.
    $MPA_CONVERGENCE_ANGLE.
    $MPA_BASELINE_LENGTH.
    $OFFSET_NEXT_IFD_NULL.
    $MPA_VALUES;

$segdata_right =
    MARKER_APP2.
    chr(0x00).chr(0x60).  //lenght of APP2
    $MP_FORMAT_IDENTIFIER.
    $MP_ENDIAN.
    $OFFSET_TO_FIRST_IFD.
    $MPA_COUNT_B.
    $MPI_VERSION.   //MPI version first picture = MPA version second picture
    $MPA_INDIVIDUAL_IMAGE_NUMBER_B.
    $MPA_BASE_VIEWPOINT_NUMBER.
    $MPA_CONVERGENCE_ANGLE_B.
    $MPA_BASELINE_LENGTH_B.
    $OFFSET_NEXT_IFD_NULL.
    $MPA_VALUES;

//////////////////////////////////////////
//insert data in the APP2 segment
$img_data_left = substr_replace($img_data_left,
                                $segdata_left,
                                $APP2_POS_LEFT + 1,
                                0);

$img_data_right = substr_replace($img_data_right,
                                 $segdata_right,
                                 $APP2_POS_RIGHT + 1,
                                 0);

//write mpo file
$mpo = $img_data_left.$img_data_right;
file_put_contents($filename_out, $mpo);
?>
