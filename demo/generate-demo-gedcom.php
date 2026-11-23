<?php
/**
 * Regenerates demo.ged's seeded family from Wikidata.
 *
 * A dev-only tool, not part of the distributed plugin (see .gitattributes).
 * Names, dates and places for the people below are fetched live rather than
 * hand-typed, so this file is the source of truth to re-run when Wikidata's
 * data changes, not the generated demo.ged.
 *
 * Usage: php demo/generate-demo-gedcom.php
 */

namespace Family_Wiki\Demo;

/**
 * Who is in the demo tree, and how they are related. Curated by hand from
 * the House of Habsburg's Wikidata entries: Franz Karl of Austria and his
 * descendants down to Otto von Habsburg's generation, stopping there so
 * nobody in the tree is alive today. Wikidata records more children and
 * marriages than this for some of these people (Karl Ludwig had three
 * wives, Karl I and Zita had eight children); only the branch that carries
 * the story forward is kept.
 *
 * Xref => [Wikidata QID, GEDCOM name].
 *
 * The name is written out by hand rather than taken from Wikidata's label:
 * labels carry titles ("Archduke Franz Karl of Austria") that do not belong
 * in a GEDCOM NAME field, and Wikidata's given-name property is both
 * multi-valued and unordered, so deriving "Franz Karl" from it is not
 * reliable. Surnames follow genealogical convention — a person's own birth
 * house with its "von" particle, not a husband's — matching how these
 * people are commonly known (Otto von Habsburg, not Otto Habsburg) and how
 * Familypedia's own GEDCOM export would render the same people.
 */
const PEOPLE = array(
	'FRANZ_KARL'            => array( 'Q156659', 'Franz Karl /von Habsburg/' ),
	'SOPHIE_BAYERN'         => array( 'Q57653', 'Sophie /von Bayern/' ),
	'FRANZ_JOSEPH'          => array( 'Q51056', 'Franz Joseph /von Habsburg/' ),
	'ELISABETH_SISI'        => array( 'Q150782', 'Elisabeth /von Bayern/' ),
	'KARL_LUDWIG'           => array( 'Q78519', 'Karl Ludwig /von Habsburg/' ),
	'MARIA_ANNUNZIATA'      => array( 'Q211673', 'Maria Annunziata /von Bourbon-Two Sicilies/' ),
	'SOPHIE_HAB'            => array( 'Q112117', 'Sophie /von Habsburg/' ),
	'GISELA'                => array( 'Q231871', 'Gisela /von Habsburg/' ),
	'LEOPOLD_BAYERN'        => array( 'Q61015', 'Leopold /von Bayern/' ),
	'RUDOLF'                => array( 'Q152923', 'Rudolf /von Habsburg/' ),
	'STEPHANIE_BELGIEN'     => array( 'Q170197', 'Stéphanie /von Belgien/' ),
	'MARIE_VALERIE'         => array( 'Q234002', 'Marie Valerie /von Habsburg/' ),
	'FRANZ_SALVATOR'        => array( 'Q78536', 'Franz Salvator /von Habsburg-Toscana/' ),
	'FRANZ_FERDINAND'       => array( 'Q43063', 'Franz Ferdinand /von Habsburg/' ),
	'SOPHIE_CHOTEK'         => array( 'Q153099', 'Sophie /von Chotek/' ),
	'OTTO_FRANZ'            => array( 'Q84470', 'Otto Franz /von Habsburg/' ),
	'MARIA_JOSEPHA_SACHSEN' => array( 'Q58016', 'Maria Josepha /von Sachsen/' ),
	'ELISABETH_MARIE'       => array( 'Q93390', 'Elisabeth Marie /von Habsburg/' ),
	'MAX_HOHENBERG'         => array( 'Q78596', 'Maximilian /von Hohenberg/' ),
	'ERNST_HOHENBERG'       => array( 'Q78659', 'Ernst /von Hohenberg/' ),
	'SOPHIE_HOHENBERG'      => array( 'Q112102', 'Sophie /von Hohenberg/' ),
	'KARL_I'                => array( 'Q51068', 'Karl /von Habsburg/' ),
	'ZITA'                  => array( 'Q50926', 'Zita /von Bourbon-Parma/' ),
	'OTTO_HABSBURG'         => array( 'Q76343', 'Otto /von Habsburg/' ),
	'REGINA'                => array( 'Q77335', 'Regina /von Sachsen-Meiningen/' ),
	'ADELHEID_HABSBURG'     => array( 'Q898126', 'Adelheid /von Habsburg/' ),
);

/**
 * Marriages. 'children' lists which of PEOPLE belong to this couple; most
 * of these people have other, excluded children per the note above.
 */
const FAMILIES = array(
	array(
		'husb'     => 'FRANZ_KARL',
		'wife'     => 'SOPHIE_BAYERN',
		'children' => array( 'FRANZ_JOSEPH', 'KARL_LUDWIG' ),
	),
	array(
		'husb'     => 'FRANZ_JOSEPH',
		'wife'     => 'ELISABETH_SISI',
		'children' => array( 'SOPHIE_HAB', 'GISELA', 'RUDOLF', 'MARIE_VALERIE' ),
	),
	array(
		'husb'     => 'KARL_LUDWIG',
		'wife'     => 'MARIA_ANNUNZIATA',
		'children' => array( 'FRANZ_FERDINAND', 'OTTO_FRANZ' ),
	),
	array(
		'husb'     => 'LEOPOLD_BAYERN',
		'wife'     => 'GISELA',
		'children' => array(),
	),
	array(
		'husb'     => 'RUDOLF',
		'wife'     => 'STEPHANIE_BELGIEN',
		'children' => array( 'ELISABETH_MARIE' ),
	),
	array(
		'husb'     => 'FRANZ_SALVATOR',
		'wife'     => 'MARIE_VALERIE',
		'children' => array(),
	),
	array(
		'husb'     => 'FRANZ_FERDINAND',
		'wife'     => 'SOPHIE_CHOTEK',
		'children' => array( 'MAX_HOHENBERG', 'ERNST_HOHENBERG', 'SOPHIE_HOHENBERG' ),
	),
	array(
		'husb'     => 'OTTO_FRANZ',
		'wife'     => 'MARIA_JOSEPHA_SACHSEN',
		'children' => array( 'KARL_I' ),
	),
	array(
		'husb'     => 'KARL_I',
		'wife'     => 'ZITA',
		'children' => array( 'OTTO_HABSBURG', 'ADELHEID_HABSBURG' ),
	),
	array(
		'husb'     => 'OTTO_HABSBURG',
		'wife'     => 'REGINA',
		'children' => array(),
	),
);

const ROOT_XREF = 'FRANZ_KARL';

const SEX_MALE_QID   = 'Q6581097';
const SEX_FEMALE_QID = 'Q6581072';

function sparql_query( $query ) {
	$url = 'https://query.wikidata.org/sparql?' . http_build_query(
		array(
			'query'  => $query,
			'format' => 'json',
		)
	);

	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => array(
				'Accept: application/sparql-results+json',
				'User-Agent: familypedia-demo-gedcom-script/0.1 (https://github.com/akirk/family-wiki)',
			),
			CURLOPT_TIMEOUT        => 30,
		)
	);
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( false === $body || 200 !== $code ) {
		fwrite( STDERR, "Wikidata query failed (HTTP {$code}).\n" );
		exit( 1 );
	}

	$decoded = json_decode( $body, true );
	if ( ! isset( $decoded['results']['bindings'] ) ) {
		fwrite( STDERR, "Unexpected Wikidata response.\n" );
		exit( 1 );
	}

	return $decoded['results']['bindings'];
}

function qid_values( $qids ) {
	return implode( ' ', array_map( static fn( $qid ) => 'wd:' . $qid, $qids ) );
}

function wikidata_date_to_gedcom( $value ) {
	// Wikidata time literals look like "1830-08-18T00:00:00Z".
	if ( ! preg_match( '/^(-?\d+)-(\d{2})-(\d{2})T/', $value, $matches ) ) {
		return '';
	}
	$months = array(
		1  => 'JAN',
		2  => 'FEB',
		3  => 'MAR',
		4  => 'APR',
		5  => 'MAY',
		6  => 'JUN',
		7  => 'JUL',
		8  => 'AUG',
		9  => 'SEP',
		10 => 'OCT',
		11 => 'NOV',
		12 => 'DEC',
	);
	return sprintf( '%d %s %d', (int) $matches[3], $months[ (int) $matches[2] ], (int) $matches[1] );
}

function fetch_people( $people ) {
	$qids   = array_column( $people, 0 );
	$values = qid_values( $qids );
	$query  = <<<SPARQL
SELECT ?person ?sex ?birth ?birthPlaceLabel ?death ?deathPlaceLabel WHERE {
  VALUES ?person { {$values} }
  OPTIONAL { ?person wdt:P21 ?sex . }
  OPTIONAL { ?person wdt:P569 ?birth . }
  OPTIONAL { ?person wdt:P19 ?birthPlace . }
  OPTIONAL { ?person wdt:P570 ?death . }
  OPTIONAL { ?person wdt:P20 ?deathPlace . }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
SPARQL;

	$rows       = sparql_query( $query );
	$by_qid     = array();
	foreach ( $rows as $row ) {
		$qid = basename( $row['person']['value'] );
		$sex_qid = isset( $row['sex']['value'] ) ? basename( $row['sex']['value'] ) : '';
		$by_qid[ $qid ] = array(
			'sex'         => SEX_MALE_QID === $sex_qid ? 'M' : ( SEX_FEMALE_QID === $sex_qid ? 'F' : '' ),
			'birth_date'  => isset( $row['birth']['value'] ) ? wikidata_date_to_gedcom( $row['birth']['value'] ) : '',
			'birth_place' => isset( $row['birthPlaceLabel']['value'] ) ? $row['birthPlaceLabel']['value'] : '',
			'death_date'  => isset( $row['death']['value'] ) ? wikidata_date_to_gedcom( $row['death']['value'] ) : '',
			'death_place' => isset( $row['deathPlaceLabel']['value'] ) ? $row['deathPlaceLabel']['value'] : '',
		);
	}

	$missing = array_diff( $qids, array_keys( $by_qid ) );
	if ( $missing ) {
		fwrite( STDERR, 'Wikidata returned nothing for: ' . implode( ', ', $missing ) . "\n" );
		exit( 1 );
	}

	$facts = array();
	foreach ( $people as $xref => $person ) {
		$facts[ $xref ] = $by_qid[ $person[0] ];
	}

	return $facts;
}

function family_xrefs( $families ) {
	$famc = array(); // xref => the one family this person is a child in.
	$fams = array(); // xref => families this person is a spouse in.
	foreach ( $families as $index => $family ) {
		$fam_xref = 'F' . ( $index + 1 );
		$fams[ $family['husb'] ][] = $fam_xref;
		$fams[ $family['wife'] ][] = $fam_xref;
		foreach ( $family['children'] as $child ) {
			$famc[ $child ] = $fam_xref;
		}
	}
	return array( $famc, $fams );
}

function build_gedcom( $people, $facts, $families ) {
	list( $famc, $fams ) = family_xrefs( $families );

	$lines   = array();
	$lines[] = '0 HEAD';
	$lines[] = '1 SOUR Familypedia';
	$lines[] = '1 GEDC';
	$lines[] = '2 VERS 5.5.1';
	$lines[] = '2 FORM LINEAGE-LINKED';
	$lines[] = '1 CHAR UTF-8';

	foreach ( $people as $xref => $person ) {
		$fact    = $facts[ $xref ];
		$lines[] = "0 @{$xref}@ INDI";
		$lines[] = '1 NAME ' . $person[1];
		if ( $fact['sex'] ) {
			$lines[] = '1 SEX ' . $fact['sex'];
		}
		if ( $fact['birth_date'] || $fact['birth_place'] ) {
			$lines[] = '1 BIRT';
			if ( $fact['birth_date'] ) {
				$lines[] = '2 DATE ' . $fact['birth_date'];
			}
			if ( $fact['birth_place'] ) {
				$lines[] = '2 PLAC ' . $fact['birth_place'];
			}
		}
		if ( $fact['death_date'] || $fact['death_place'] ) {
			$lines[] = '1 DEAT';
			if ( $fact['death_date'] ) {
				$lines[] = '2 DATE ' . $fact['death_date'];
			}
			if ( $fact['death_place'] ) {
				$lines[] = '2 PLAC ' . $fact['death_place'];
			}
		}
		if ( isset( $famc[ $xref ] ) ) {
			$lines[] = "1 FAMC @{$famc[ $xref ]}@";
		}
		foreach ( $fams[ $xref ] ?? array() as $fam_xref ) {
			$lines[] = "1 FAMS @{$fam_xref}@";
		}
	}

	foreach ( $families as $index => $family ) {
		$fam_xref = 'F' . ( $index + 1 );
		$lines[]  = "0 @{$fam_xref}@ FAM";
		$lines[]  = "1 HUSB @{$family['husb']}@";
		$lines[]  = "1 WIFE @{$family['wife']}@";
		foreach ( $family['children'] as $child ) {
			$lines[] = "1 CHIL @{$child}@";
		}
	}

	$lines[] = '0 TRLR';

	return implode( "\n", $lines ) . "\n";
}

$facts  = fetch_people( PEOPLE );
$gedcom = build_gedcom( PEOPLE, $facts, FAMILIES );

$out = __DIR__ . '/demo.ged';
file_put_contents( $out, $gedcom );
fwrite( STDERR, 'Wrote ' . $out . ' (' . count( PEOPLE ) . " people).\n" );
