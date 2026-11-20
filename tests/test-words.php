<?php
/**
 * Standalone tests for TBTDIC_Words. WordPress is not loaded.
 *
 * Run: php tests/test-words.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-tbtdic-words.php';

$failures = 0;
$count    = 0;

function check( $label, $actual, $expected ) {
	global $failures, $count;
	++$count;
	if ( $actual === $expected ) {
		echo "ok   - {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL - {$label}\n       expected " . var_export( $expected, true ) . "\n       got      " . var_export( $actual, true ) . "\n";
}

/* ---------- clean_term ---------- */

check( 'clean_term: trims and collapses inner spaces', TBTDIC_Words::clean_term( "  to   come\tup \n with  " ), 'to come up with' );
check( 'clean_term: strips control characters', TBTDIC_Words::clean_term( "resi\x00li\x07ent" ), 'resilient' );
check( 'clean_term: cut to 120', mb_strlen( TBTDIC_Words::clean_term( str_repeat( 'ż', 200 ) ) ), 120 );
check( 'clean_term: keeps case and accents', TBTDIC_Words::clean_term( ' Café ' ), 'Café' );
check( 'clean_term: non-string → empty', TBTDIC_Words::clean_term( array( 'x' ) ), '' );
check( 'clean_name: cut to 60', mb_strlen( TBTDIC_Words::clean_name( str_repeat( 'a', 80 ) ) ), 60 );

/* ---------- key ---------- */

check( 'key: lower-case', TBTDIC_Words::key( 'Resilient' ), 'resilient' );
check( 'key: accents folded', TBTDIC_Words::key( 'Café Crème' ), 'cafe creme' );
check( 'key: ł → l and Polish letters', TBTDIC_Words::key( 'Zakłócenie ŻÓŁĆ' ), 'zaklocenie zolc' );
check( 'key: spaces collapsed', TBTDIC_Words::key( "  a   piece of\tcake " ), 'a piece of cake' );
check( 'key: same word, different case and accent → same key', TBTDIC_Words::key( 'RÉSILIENT' ) === TBTDIC_Words::key( 'resilient' ), true );
check( 'key: é folds to e', TBTDIC_Words::key( 'résumé' ), 'resume' );

/* ---------- speak_text ---------- */

check( 'speak_text: drops "(n.)"', TBTDIC_Words::speak_text( 'disruption (n.)' ), 'disruption' );
check( 'speak_text: drops a note in the middle', TBTDIC_Words::speak_text( 'to rely (on sb) on' ), 'to rely on' );
check( 'speak_text: drops square-bracket notes', TBTDIC_Words::speak_text( 'issue [BrE]' ), 'issue' );
check( 'speak_text: plain term unchanged', TBTDIC_Words::speak_text( 'a piece of cake' ), 'a piece of cake' );
check( 'speak_text: only a note → the term itself', TBTDIC_Words::speak_text( '(n.)' ), '(n.)' );

/* ---------- percent / group_percent ---------- */

check( 'percent: half way', TBTDIC_Words::percent( 5, 10 ), 50.0 );
check( 'percent: capped at 100', TBTDIC_Words::percent( 14, 10 ), 100.0 );
check( 'percent: never below 0', TBTDIC_Words::percent( -2, 10 ), 0.0 );
check( 'percent: decimal progress', TBTDIC_Words::percent( '2.50', 10 ), 25.0 );
check( 'percent: default learnt is 10', TBTDIC_Words::percent( 10 ), 100.0 );
check( 'percent: learnt 0 → 0', TBTDIC_Words::percent( 5, 0 ), 0.0 );
check( 'group_percent: empty group → 0', TBTDIC_Words::group_percent( array(), 10 ), 0.0 );
check( 'group_percent: average', TBTDIC_Words::group_percent( array( 10, 0 ), 10 ), 50.0 );
check( 'group_percent: one decimal', TBTDIC_Words::group_percent( array( 10, 0, 0 ), 10 ), 33.3 );
check( 'group_percent: words over the cap count as 100', TBTDIC_Words::group_percent( array( 20, 10 ), 10 ), 100.0 );
check( 'is_learned', array( TBTDIC_Words::is_learned( 10, 10 ), TBTDIC_Words::is_learned( 9.75, 10 ) ), array( true, false ) );

/* ---------- parse_ai ---------- */

check(
	'parse_ai: valid answer',
	TBTDIC_Words::parse_ai( '{"translation":"odporny, prężny","phonetic":"/rɪˈzɪl.i.ənt/","example":"Children are often more resilient than adults think."}' ),
	array(
		'translation' => 'odporny, prężny',
		'phonetic'    => '/rɪˈzɪl.i.ənt/',
		'example'     => 'Children are often more resilient than adults think.',
	)
);
check( 'parse_ai: phonetic without slashes dropped', TBTDIC_Words::parse_ai( '{"translation":"wynik","phonetic":"ˈaʊt.kʌm","example":"x"}' )['phonetic'], '' );
check( 'parse_ai: phonetic with one slash dropped', TBTDIC_Words::parse_ai( '{"phonetic":"/ˈaʊt.kʌm"}' )['phonetic'], '' );
check( 'parse_ai: phonetic over 80 dropped', TBTDIC_Words::parse_ai( '{"phonetic":"/' . str_repeat( 'a', 85 ) . '/"}' )['phonetic'], '' );
check( 'parse_ai: translation cut to 190', mb_strlen( TBTDIC_Words::parse_ai( '{"translation":"' . str_repeat( 'ą', 250 ) . '"}' )['translation'] ), 190 );
check( 'parse_ai: example cut to 300', mb_strlen( TBTDIC_Words::parse_ai( '{"example":"' . str_repeat( 'b', 400 ) . '"}' )['example'] ), 300 );
check( 'parse_ai: tags stripped', TBTDIC_Words::parse_ai( '{"translation":"<b>wynik</b>","example":"<script>x()</script>It <i>worked</i>."}' ), array( 'translation' => 'wynik', 'phonetic' => '', 'example' => 'x()It worked.' ) );
check( 'parse_ai: extra keys ignored', array_keys( TBTDIC_Words::parse_ai( '{"translation":"a","foo":"b"}' ) ), array( 'translation', 'phonetic', 'example' ) );
check( 'parse_ai: non-string field becomes empty', TBTDIC_Words::parse_ai( '{"translation":"a","example":["x"]}' )['example'], '' );
check( 'parse_ai: malformed JSON → null', TBTDIC_Words::parse_ai( '{"translation":' ), null );
check( 'parse_ai: not an object → null', TBTDIC_Words::parse_ai( '["a","b"]' ), null );
check( 'parse_ai: none of the fields → null', TBTDIC_Words::parse_ai( '{"foo":"bar"}' ), null );
check( 'parse_ai: empty string → null', TBTDIC_Words::parse_ai( '' ), null );
check( 'parse_ai: markdown fence → null', TBTDIC_Words::parse_ai( "```json\n{\"translation\":\"a\"}\n```" ), null );

echo "\n{$count} checks, {$failures} failed.\n";
exit( $failures ? 1 : 0 );
