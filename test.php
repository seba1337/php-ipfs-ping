<?php

/* gm(): function for generating messages
   it appends to the start of the string a byte value of the length of the string
   i.e. if we want to write "abc", so string length of 3
   it will return the string "\0x03" . "abc"

   this function is valid up to length of 254, above is more complex
*/
function gm($str) {
	$len = strlen($str);
	return chr($len) . $str;
}

/* rm(): function for reading messages
   it reads first the first byte, which is the length of the message
   then it reads the stream for the received amount of bytes and returns it

   this function is valid up to length of 254, above is more complex
*/
function rm() {
	global $fp;
	$len = ord(fgetc($fp));
	$str = fread($fp, $len);
	return $str;
}


//we open a localhost connection to IPFS, started with --disable-transport-encryption
//this is to disable encryption to make things simpler
$fp = fsockopen('localhost', 4001);


//read intro which should be /multistream/1.0.0\n
echo "Rx: " . rm();

//we send back the same thing
$tx = "/multistream/1.0.0\n";
echo "Tx: " . $tx;
fwrite($fp, gm($tx));

//we send that we want to initiate a plaintext session
$tx = "/plaintext/1.0.0\n";
echo "Tx: " . $tx;
fwrite($fp, gm($tx));

//we receive back the same thing
echo "Rx: " . rm();


?>
