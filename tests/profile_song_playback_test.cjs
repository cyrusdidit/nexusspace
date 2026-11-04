const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/spotify-profile-song.js', 'utf8');
function setup(trackId) {
 const events = {}, listeners = {}, scripts = [];
 let plays = 0, pauses = 0, creates = 0;
 const host = { replaceChildren() {}, append() {} };
 const dismiss = { addEventListener(name, handler) { events.dismiss = handler; } };
 const banner = { dataset: { trackId }, querySelector(s) { return s === '[data-profile-song-browser-player]' ? host : s === '[data-profile-song-dismiss]' ? dismiss : null; } };
 const controller = { addListener(name, callback) { listeners[name] = callback; }, play() { plays++; }, pause() { pauses++; }, destroy() {} };
 const window = { addEventListener(name, handler) { events[name] = handler; } };
 const document = { body: { dataset: { spotifyProfileSongEndpoint: '/song', settingsToken: 'token' }, append(script) { scripts.push(script); } }, querySelector() { return banner; }, querySelectorAll() { return []; }, createElement() { return {}; }, addEventListener() {} };
 vm.runInNewContext(source, { window, document, fetch() { throw Error('Browser playback must not call Spotify Connect or fetch account tokens'); } });
 window.onSpotifyIframeApiReady({ createController(target, options, callback) {
  creates++;
  assert.equal(options.uri, 'spotify:track:' + trackId);
  assert.equal(options.height, 80);
  callback(controller);
 } });
 return { events, listeners, scripts, banner, stats: () => ({ plays, pauses, creates }) };
}
const player = setup('1234567890123456789012');
assert.equal(player.scripts[0].src, 'https://open.spotify.com/embed/iframe-api/v1');
player.listeners.ready();
assert.equal(player.stats().plays, 1, 'Attempt autoplay inside the embed');
player.events.click();
assert.equal(player.stats().plays, 2, 'Retry blocked autoplay on interaction');
player.listeners.playback_update({ data: { isPaused: false } });
player.events.click();
assert.equal(player.stats().plays, 2, 'Leave a playing song alone');
player.events.dismiss();
assert.equal(player.stats().pauses, 1, 'Dismiss pauses only the embed');
player.events.click();
assert.equal(player.stats().plays, 2, 'Keep dismissed player paused');
assert.equal(setup('').stats().creates, 0, 'Do not create a player without a song');
assert.doesNotMatch(source, /sdk\.scdn\.co|Spotify\.Player|device_id|fetchPlaybackToken/);
console.log('Profile song browser embed tests passed.');
