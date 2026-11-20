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

/* ---------- ays_progress ---------- */

check( 'ays_progress: completed → learned', TBTDIC_Words::ays_progress( 1, 27.5 ), 10.0 );
check( 'ays_progress: completed as string', TBTDIC_Words::ays_progress( '1', '0' ), 10.0 );
check( 'ays_progress: 27.5% → 2.75', TBTDIC_Words::ays_progress( 0, 27.5 ), 2.75 );
check( 'ays_progress: 27.5% from a DB string', TBTDIC_Words::ays_progress( '0', '27.50' ), 2.75 );
check( 'ays_progress: 150% → 10', TBTDIC_Words::ays_progress( 0, 150 ), 10.0 );
check( 'ays_progress: 0 → 0', TBTDIC_Words::ays_progress( 0, 0 ), 0.0 );
check( 'ays_progress: negative → 0', TBTDIC_Words::ays_progress( 0, -20 ), 0.0 );
check( 'ays_progress: not a number → 0', TBTDIC_Words::ays_progress( 0, null ), 0.0 );
check( 'ays_progress: two decimals', TBTDIC_Words::ays_progress( 0, 33.333 ), 3.33 );
check( 'ays_progress: 2.75 shows as 27.5% (one decimal, as the page)', round( TBTDIC_Words::percent( TBTDIC_Words::ays_progress( 0, 27.5 ) ), 1 ), 27.5 );

/* The copy uses "Points to learn a word" (0.2.0): a word AYS marked learned
   always comes in learned, and the percentage carries over. */
check( 'ays_progress: learnt 20, completed → 20 (learned)', TBTDIC_Words::ays_progress( 1, 27.5, 20 ), 20.0 );
check( 'ays_progress: learnt 20, completed is learned', TBTDIC_Words::is_learned( TBTDIC_Words::ays_progress( 1, 0, 20 ), 20 ), true );
check( 'ays_progress: learnt 3, completed is learned', TBTDIC_Words::is_learned( TBTDIC_Words::ays_progress( '1', '40', 3 ), 3 ), true );
check( 'ays_progress: learnt 20, 27.5% → 5.5', TBTDIC_Words::ays_progress( 0, 27.5, 20 ), 5.5 );
check( 'ays_progress: learnt 20, 27.5% still shows 27.5%', round( TBTDIC_Words::percent( TBTDIC_Words::ays_progress( 0, 27.5, 20 ), 20 ), 1 ), 27.5 );
check( 'ays_progress: learnt 7, 33.333% → 2.33', TBTDIC_Words::ays_progress( 0, 33.333, 7 ), 2.33 );
check( 'ays_progress: learnt 20, 150% → 20', TBTDIC_Words::ays_progress( 0, 150, 20 ), 20.0 );
check( 'ays_progress: learnt 0 falls back to the default', TBTDIC_Words::ays_progress( 1, 0, 0 ), 10.0 );

/* ---------- learning settings ---------- */

check( 'learning: defaults pass through', TBTDIC_Words::learning( 10, 1, 0.25 ), array( 'learnt' => 10, 'right' => 1.0, 'wrong' => 0.25 ) );
check( 'learning: from option strings', TBTDIC_Words::learning( '12', '1.5', '0.75' ), array( 'learnt' => 12, 'right' => 1.5, 'wrong' => 0.75 ) );
check( 'learning: not numeric → defaults', TBTDIC_Words::learning( '', null, 'x' ), array( 'learnt' => 10, 'right' => 1.0, 'wrong' => 0.25 ) );
check( 'learning: learnt clamped to 1–100', array( TBTDIC_Words::learning( 0, 1, 0 )['learnt'], TBTDIC_Words::learning( 500, 1, 0 )['learnt'] ), array( 1, 100 ) );
check( 'learning: right/wrong clamped to 0–100', array( TBTDIC_Words::learning( 10, -3, 0 )['right'], TBTDIC_Words::learning( 10, 1, 250 )['wrong'] ), array( 0.0, 100.0 ) );
check( 'learning: quarter steps', array( TBTDIC_Words::learning( 10, 0.3, 0.1 )['right'], TBTDIC_Words::learning( 10, 0.3, 0.1 )['wrong'] ), array( 0.25, 0.0 ) );

/* ---------- answer ---------- */

check( 'answer: right +1', TBTDIC_Words::answer( 2.75, true, 1, 0.25, 10 ), 3.75 );
check( 'answer: wrong −0.25', TBTDIC_Words::answer( 2.75, false, 1, 0.25, 10 ), 2.5 );
check( 'answer: from a DB string', TBTDIC_Words::answer( '2.75', true, 1, 0.25, 10 ), 3.75 );
check( 'answer: clamp at 0', TBTDIC_Words::answer( 0.1, false, 1, 0.25, 10 ), 0.0 );
check( 'answer: wrong at 0 stays 0', TBTDIC_Words::answer( 0, false, 1, 0.25, 10 ), 0.0 );
check( 'answer: clamp at learnt', TBTDIC_Words::answer( 9.5, true, 1, 0.25, 10 ), 10.0 );
check( 'answer: right at learnt stays learnt', TBTDIC_Words::answer( 10, true, 1, 0.25, 10 ), 10.0 );
check( 'answer: quarter steps add up', TBTDIC_Words::answer( TBTDIC_Words::answer( 1, true, 0.75, 0.25, 10 ), false, 0.75, 0.25, 10 ), 1.5 );
check( 'answer: right reaches learned', TBTDIC_Words::is_learned( TBTDIC_Words::answer( 9, true, 1, 0.25, 10 ), 10 ), true );
check( 'answer: progress over a lower learnt is clamped', TBTDIC_Words::answer( 12, false, 1, 0.25, 5 ), 5.0 );

/* ---------- game_ref ---------- */

check( 'game_ref: tr shape', TBTDIC_Words::game_ref( 'tr', array( 3, 12 ) ), 'tr:' . md5( '3-12' ) );
check( 'game_ref: w shape', TBTDIC_Words::game_ref( 'w', array( 7 ) ), 'w:' . md5( '7' ) );
check( 'game_ref: order does not matter (numeric sort)', TBTDIC_Words::game_ref( 'tr', array( 12, 3 ) ), TBTDIC_Words::game_ref( 'tr', array( 3, 12 ) ) );
check( 'game_ref: duplicates do not matter', TBTDIC_Words::game_ref( 'tr', array( 3, 12, 3, '12' ) ), TBTDIC_Words::game_ref( 'tr', array( 3, 12 ) ) );
check( 'game_ref: games differ', TBTDIC_Words::game_ref( 'tr', array( 3 ) ) === TBTDIC_Words::game_ref( 'w', array( 3 ) ), false );
check( 'game_ref: unknown type → empty', TBTDIC_Words::game_ref( 'x', array( 3 ) ), '' );
check( 'game_ref: fits Notes object_ref (64)', strlen( TBTDIC_Words::game_ref( 'tr', range( 1, 200 ) ) ) <= 64, true );

/* ---------- game_title ---------- */

check( 'game_title: translation', TBTDIC_Words::game_title( 'tr', array( 'Science Words', 'HR words' ) ), 'Find the translation: Science Words, HR words' );
check( 'game_title: word', TBTDIC_Words::game_title( 'w', array( 'My English Words' ) ), 'Find the word: My English Words' );
$long = TBTDIC_Words::game_title( 'tr', array( str_repeat( 'ż', 80 ), str_repeat( 'b', 80 ) ) );
check( 'game_title: cut to 120 with …', array( mb_strlen( $long ), mb_substr( $long, -1 ) ), array( 120, '…' ) );
check( 'game_title: exactly 120 is not cut', mb_strlen( TBTDIC_Words::game_title( 'w', array( str_repeat( 'a', 105 ) ) ) ), 120 );
check( 'game_title: unknown type → empty', TBTDIC_Words::game_title( 'x', array( 'A' ) ), '' );

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
