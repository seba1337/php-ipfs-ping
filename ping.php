<?php

//First we implement basic functions to read/write the first part of the protocol

/* gm($data): function for generating messages
	$data: data to be sent

   it appends to the start of the data string a byte value of the length of the string
   i.e. if we want to write "abc", so string length of 3
   it will return the string "\0x03" . "abc"

   this function is valid up to length of 254, above is more complex
*/
function gm($data) {
	$len = strlen($data);
	return chr($len) . $data; //we return length byte and data
}

/* rm(): function for reading messages
   it reads first the first byte, which is the length of the message
   then it reads the stream for the received amount of bytes and returns it

   this function is valid up to length of 254, above is more complex
*/
function rm() {
	global $fp;
	$len = ord(fgetc($fp));	//first byte is length

	if ($len == 0) {
		return false;
	}

	$data = fread($fp, $len); //we read that much data

	return $data; //we return data without the length byte
}


//Now we implement functions to read/write the multiplex part of the protocol

//We first define the constants that define header flags (types)
define("NewStream", 0);
define("MessageReceiver", 1);
define("MessageInitiator", 2);
define("CloseReceiver", 3);
define("CloseInitiator", 4);
define("ResetReceiver", 5);
define("ResetInitiator", 6);

/* gm_mplex($data, $id, $flag, $dbllen): function for generating messages in multiplex streams of mplex type
   	$data: data to be sent
	$id: stream ID
	$flag: flag/type of message defined with constants few lines above
	$dbllen: if it should write two byte lengths, one for mplex protocol, the other for libp2p like gm(); defaults to false

   it appends to the start of the string a byte header followed by a byte value of the length of the data string
   i.e. if we want to write "abc", so string length of 3 with $id = 1, $flag = 1;
   it will return the string "\0x09" . "\0x03" . "abc"

   Header is composed from ID (first 5 bits) and type/flag (last 3 bits)

   mplex protocol is further described here https://github.com/libp2p/specs/tree/master/mplex
   this function is valid up to length of 254, above is more complex
*/

function gm_mplex($data, $id = 0, $flag = 0, $dbllen = false) {
	$header = ($id << 3) | $flag; 			//we shift ID by 3 bits to the left and add type bits

	$len = strlen($data); 				//read length of data

	//returns the header byte, byte length of the data, optionally an another byte length and data
	return chr($header) . ($dbllen ? chr($len+1):'') . chr($len) . $data;
}

/* rm_mplex(): function for reading messages in multiplex streams of mplex type

   it reads the first byte header followed by a byte value of the length of the data string
   it returns the stream ID, type of message (flag) and data in an associative array

   Header is composed from ID (first 5 bits) and type/flag (last 3 bits)

   mplex protocol is further described here https://github.com/libp2p/specs/tree/master/mplex
   this function is valid up to length of 254, above is more complex
*/

function rm_mplex() {
	global $fp;
	$header = ord(fgetc($fp)); 	//we read first the header byte
	$flag = $header & 0x07;		//right 3 bits are flag
	$id = $header >> 3;		//left 5 bits are ID

	$len = ord(fgetc($fp));		//length is next byte
	$data = fread($fp, $len);	//we read that much bytes

	return array('id' => $id, 'flag' => $flag, 'data' => $data); //we return an array with ID, flag, data
}


/* rand_ping_str(): function gor generating random 32 bytes for a ping

   it returns a random string made out 32 numbers so that it's nicely readable
*/

function rand_ping_str() {
	$rand_str = 0;
	for ($i = 0; $i < 31; $i++) {
		$rand_str .= rand(0,9);
	}

	return $rand_str;
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

//normally we receive back the same thing, but not if encryption is on
$rx = rm();
echo "Rx: " . $rx;

//if we got na\n, that means that IPFS requires secio encryption and we can't proceed with plaintext
if ($rx == "na\n") {
	exit("IPFS wasn't started with: ipfs daemon --disable-transport-encryption\n");
}


//we send now again /multistream/1.0.0, now that we started a plaintext session
$tx = "/multistream/1.0.0\n";
echo "Tx: " . $tx;
fwrite($fp, gm($tx));

//we receive back the same thing
echo "Rx: " . rm();

//we initiate now the multiplex mplex
$tx = "/mplex/6.7.0\n";
echo "Tx: " . $tx;
fwrite($fp, gm($tx));

//we receive back the same thing
echo "Rx: " . rm();

echo "\nMultiplex data [id,flag]:\n"; //now Multiplex data will start

//IPFS opens 3 distinct streams and each starts the handshake, but we'll ignore that
//we will though read the messages we received
for ($i = 0; $i < 6; $i++) { //we repeat the following 6 times, because we get 6 messages
	$r = rm_mplex();
	echo "Rx[$r[id],$r[flag]]: $r[data]";
	substr($r['data'], -1) != "\n" && print("\n"); //if data doesn't end with \n we print a \n for clarity
}

//we initiate a new stream ID=3
$id = 3;
$flag = NewStream;
$tx = 3;
echo "Tx[$id,$flag]: $tx\n";
fwrite($fp, gm_mplex($tx, $id, $flag));

//we read the response
$r = rm_mplex();
echo "Rx[$r[id],$r[flag]]: $r[data]";
substr($r['data'], -1) != "\n" && print("\n"); //if data doesn't end with \n we print a \n for clarity

//we send now back the multistream. we append double length byte, one for mplex, other for libp2p.
$id = 3;
$flag = MessageInitiator;
$tx = "/multistream/1.0.0\n";
echo "Tx[$id,$flag]: $tx";
fwrite($fp, gm_mplex($tx, $id, $flag, true));

//we start the ping handshake, again double length byte
$id = 3;
$flag = MessageInitiator;
$tx = "/ipfs/ping/1.0.0\n";
echo "Tx[$id,$flag]: $tx";
fwrite($fp, gm_mplex($tx, $id, $flag, true));


//we read the response
$r = rm_mplex();
echo "Rx[$r[id],$r[flag]]: $r[data]";
substr($r['data'], -1) != "\n" && print("\n"); //if data doesn't end with \n we print a \n for clarity

$avg = 0; //for averaging ping

$n = 5;
//we do ping 5 times
for ($i = 0; $i < $n; $i++) {
	//we start the ping, by sending a random 32 byte string. this time we send just once the byte length!
	$id = 3;
	$flag = MessageInitiator;
	$tx = rand_ping_str();
	echo "Tx[$id,$flag]: $tx\n";
	$t0 = microtime(1); //just before sending we mark time at start
	fwrite($fp, gm_mplex($tx, $id, $flag));

	//we read the response, which is just the same what we've sent
	$r = rm_mplex();
	echo "Rx[$r[id],$r[flag]]: $r[data]";
	substr($r['data'], -1) != "\n" && print("\n"); //if data doesn't end with \n we print a \n for clarity
	$t1 = microtime(1); //time of receiving

	//we write how much the roundtrip took
	echo "Ping took " . round(($t1-$t0)*1e3,2) . " ms\n";

	//we check if sent data matches to the received data
	echo "Ping data " . ($r['data'] == $tx ? 'matches':'differs') . "\n\n";

	$avg += ($t1-$t0)*1e3;
}

$avg /= $n; //we divide the ping time sum with N, so that we get the mean

echo "Average ping took " . round($avg,2) . " ms\n";


?>
