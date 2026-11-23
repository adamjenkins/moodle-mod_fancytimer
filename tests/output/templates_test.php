<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_fancytimer\output;

use mod_fancytimer\form\preset_import_form;
use mod_fancytimer\form\preset_save_form;
use mod_fancytimer\local\display_config;
use mod_fancytimer\local\presets;
use mod_fancytimer\local\recordings;
use mod_fancytimer\local\segments;
use mod_fancytimer\local\templates;
use moodle_url;

/**
 * Renders every template of the plugin with the context its page builds.
 *
 * A missing lang string renders as [[key]], so each render is checked for '[['.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(view_page::class)]
final class templates_test extends \advanced_testcase {
    /** @var \stdClass Timer instance (with cmid). */
    private \stdClass $timer;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \context_module Module context. */
    private \context_module $context;

    /**
     * A course with a threeperson timer; the admin is the viewer.
     */
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->timer = $this->getDataGenerator()->create_module(
            'fancytimer',
            ['course' => $course->id, 'name' => 'Speaking practice']
        );
        $this->cm = get_coursemodule_from_id('fancytimer', $this->timer->cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $PAGE->set_url('/mod/fancytimer/view.php', ['id' => $this->cm->id]);
        $PAGE->set_context($this->context);
    }

    /**
     * The fresh instance record.
     *
     * @return \stdClass
     */
    private function instance(): \stdClass {
        global $DB;
        return $DB->get_record('fancytimer', ['id' => $this->timer->id], '*', MUST_EXIST);
    }

    /**
     * Render a template with the core renderer.
     *
     * @param string $template
     * @param array $context
     * @return string
     */
    private function render(string $template, array $context): string {
        global $PAGE;
        $html = $PAGE->get_renderer('core')->render_from_template($template, $context);
        $this->assertStringNotContainsString('[[', $html, "$template has a missing string");
        return $html;
    }

    /**
     * The decoded data-config of a rendered view.
     *
     * @param string $html
     * @return array
     */
    private function config_from(string $html): array {
        $this->assertSame(1, preg_match('/data-config="([^"]*)"/', $html, $m));
        $config = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsArray($config);
        return $config;
    }

    /**
     * Control mode: controls, first segment, remote panel with QR code, config for timer.js.
     */
    public function test_view_control_mode(): void {
        $page = new view_page($this->instance(), $this->cm, $this->context, true);
        $html = $this->render('mod_fancytimer/view', $page->export_for_template($GLOBALS['PAGE']->get_renderer('core')));

        $this->assertStringContainsString('data-mode="control"', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringContainsString('>' . get_string('control_start', 'mod_fancytimer') . '<', $html);
        $this->assertStringContainsString('Person 1 is speaking', $html);
        $this->assertStringContainsString('Change groups!', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString(
            (new moodle_url('/mod/fancytimer/remote.php', ['id' => $this->cm->id]))->out(),
            $html
        );
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('role="timer"', $html);
        $this->assertStringContainsString('2:00', $html);
        $this->assertStringNotContainsString('mod-fancytimer-mirrornotice', $html);

        $config = $this->config_from($html);
        $this->assertSame('control', $config['mode']);
        $this->assertSame((int)$this->cm->id, $config['cmid']);
        $this->assertCount(4, $config['segments']);
        $this->assertTrue($config['segments'][3]['isrotation']);
        $this->assertSame(display_config::POLL_CONTROL_MS, $config['pollms']);
        $this->assertSame(390, $config['cyclelength']);
    }

    /**
     * Mirror mode: the notice, no controls, no remote link.
     */
    public function test_view_mirror_mode(): void {
        $page = new view_page($this->instance(), $this->cm, $this->context, false);
        $html = $this->render('mod_fancytimer/view', $page->export_for_template($GLOBALS['PAGE']->get_renderer('core')));

        $this->assertStringContainsString('data-mode="mirror"', $html);
        $this->assertStringContainsString(s(get_string('mirrornotice', 'mod_fancytimer')), $html);
        $this->assertStringContainsString(get_string('mirrorwaiting', 'mod_fancytimer'), $html);
        $this->assertStringNotContainsString('data-action="start"', $html);
        $this->assertStringNotContainsString('remote.php', $html);
        $this->assertStringContainsString('Person 1 is speaking', $html);
        $this->assertSame('mirror', $this->config_from($html)['mode']);
    }

    /**
     * No segments: the empty notice with a link to the segment editor, and no timer root.
     */
    public function test_view_without_segments(): void {
        global $DB;
        $DB->delete_records('fancytimer_segments', ['fancytimerid' => $this->timer->id]);
        $page = new view_page($this->instance(), $this->cm, $this->context, true);
        $html = $this->render('mod_fancytimer/view', $page->export_for_template($GLOBALS['PAGE']->get_renderer('core')));

        $this->assertStringContainsString(get_string('nosegments', 'mod_fancytimer'), $html);
        $this->assertStringContainsString(get_string('addsegments', 'mod_fancytimer'), $html);
        $this->assertStringContainsString('/mod/fancytimer/edit.php?id=' . $this->cm->id, $html);
        $this->assertStringNotContainsString('data-config=', $html);
        $this->assertStringNotContainsString('data-action="start"', $html);
    }

    /**
     * view.php renders the page with $OUTPUT->render($page), so that path must find the template.
     */
    public function test_view_page_renders_through_renderer(): void {
        global $PAGE;
        $page = new view_page($this->instance(), $this->cm, $this->context, true);
        $html = $PAGE->get_renderer('core')->render($page);
        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringContainsString('Person 1 is speaking', $html);
    }

    /**
     * Recorder with one recording and with the library full.
     */
    public function test_recorder(): void {
        $recording = recordings::save(
            $this->context,
            (int)$this->timer->id,
            (int)$GLOBALS['USER']->id,
            'Change to the next person',
            'audio/webm',
            'fake-audio-bytes'
        );
        $url = recordings::url($this->context, (int)$recording->id)->out(false);
        $context = [
            'rootid' => 'mod-fancytimer-recorder-test',
            'cmid' => (int)$this->cm->id,
            'recordings' => [['id' => (int)$recording->id, 'name' => $recording->name, 'url' => $url, 'hasurl' => true]],
            'hasrecordings' => true,
            'recordingurls' => json_encode(
                (object)[(string)$recording->id => $url],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
            'maxseconds' => 60,
            'maxrecordings' => recordings::MAX_PER_ACTIVITY,
            'defaultname' => get_string('recordingdefaultname', 'mod_fancytimer'),
            'canaddmore' => true,
        ];
        $html = $this->render('mod_fancytimer/recorder', $context);
        $this->assertStringContainsString('id="mod-fancytimer-recorder-test"', $html);
        $this->assertStringContainsString('data-recordingid="' . $recording->id . '"', $html);
        $this->assertStringContainsString('Change to the next person', $html);
        $this->assertStringContainsString('data-action="record"', $html);
        $this->assertStringContainsString('data-canaddmore="1"', $html);
        $this->assertStringContainsString('data-fancytimer-recordingurls=', $html);
        $this->assertStringContainsString('recording-' . $recording->id . '.webm', $html);
        $this->assertStringContainsString('class="d-none" data-region="empty"', $html);

        $context['recordings'] = [];
        $context['hasrecordings'] = false;
        $context['canaddmore'] = false;
        $html = $this->render('mod_fancytimer/recorder', $context);
        $this->assertStringContainsString('data-canaddmore="0"', $html);
        $this->assertStringContainsString('<div class="alert alert-info" data-region="full">', $html);
        $this->assertStringContainsString(get_string('norecordings', 'mod_fancytimer'), $html);
        $this->assertStringNotContainsString('data-recordingid=', $html);
    }

    /**
     * Phone remote, with the context remote.php builds.
     */
    public function test_remote(): void {
        $segmentsout = [];
        foreach (segments::get_playlist($this->instance()) as $segment) {
            $segmentsout[] = ['label' => $segment->label, 'duration' => (int)$segment->duration];
        }
        $viewurl = (new moodle_url('/mod/fancytimer/view.php', ['id' => $this->cm->id]))->out(false);
        $html = $this->render('mod_fancytimer/remote', [
            'rootid' => 'mod-fancytimer-remote-test',
            'name' => format_string('Speaking practice', true, ['context' => $this->context]),
            'config' => json_encode(['cmid' => (int)$this->cm->id, 'pollms' => display_config::POLL_CONTROL_MS,
                'segments' => $segmentsout], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'viewurl' => $viewurl,
        ]);
        $this->assertStringContainsString('id="mod-fancytimer-remote-test"', $html);
        $this->assertStringContainsString('Speaking practice', $html);
        foreach (['start', 'toggle', 'previous', 'next', 'reset', 'addtime'] as $command) {
            $this->assertStringContainsString('data-command="' . $command . '"', $html);
        }
        $this->assertStringContainsString('href="' . s($viewurl) . '"', $html);
        $this->assertStringContainsString('role="timer"', $html);
    }

    /**
     * Presets page with the bundled templates, one preset and the two real forms.
     */
    public function test_presets(): void {
        global $USER;
        presets::create((int)$USER->id, 'My pair talk', templates::get('pairs'));
        $baseurl = new moodle_url('/mod/fancytimer/presets.php', ['id' => $this->cm->id]);

        $templatesctx = [];
        foreach (templates::keys() as $key) {
            $config = templates::get($key);
            $items = [];
            foreach ($config['segments'] as $segment) {
                $items[] = ['label' => $segment['label'], 'duration' => segments::format_duration($segment['duration'])];
            }
            $templatesctx[] = [
                'key' => $key,
                'name' => format_string($config['name'], true, ['context' => $this->context]),
                'plainname' => format_string($config['name'], true, ['context' => $this->context, 'escape' => false]),
                'segments' => $items,
                'cycle' => '6:30',
            ];
        }
        $presetsctx = [];
        foreach (presets::for_user((int)$USER->id) as $preset) {
            $presetsctx[] = [
                'presetid' => (int)$preset->id,
                'name' => format_string($preset->name, true, ['context' => $this->context]),
                'plainname' => $preset->name,
                'timemodified' => userdate($preset->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            ];
        }
        $saveform = new preset_save_form($baseurl);
        $saveform->set_data(['id' => $this->cm->id, 'name' => 'Speaking practice']);
        $importform = new preset_import_form($baseurl);
        $importform->set_data(['id' => $this->cm->id]);

        $html = $this->render('mod_fancytimer/presets', [
            'cmid' => (int)$this->cm->id,
            'actionurl' => (new moodle_url('/mod/fancytimer/presets.php'))->out(false),
            'sesskey' => sesskey(),
            'templates' => $templatesctx,
            'presets' => $presetsctx,
            'haspresets' => true,
            'saveform' => $saveform->render(),
            'importform' => $importform->render(),
        ]);

        $this->assertStringContainsString(get_string('template_single', 'mod_fancytimer'), $html);
        $this->assertStringContainsString('name="key" value="single"', $html);
        $this->assertStringContainsString('value="applytemplate"', $html);
        $this->assertStringContainsString('My pair talk', $html);
        $this->assertStringContainsString('value="applypreset"', $html);
        $this->assertStringContainsString('value="exportpreset"', $html);
        $this->assertStringContainsString('value="deletepreset"', $html);
        $this->assertStringContainsString('value="exporttimer"', $html);
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertStringContainsString('id="id_name"', $html);
        $this->assertStringContainsString('Time left', $html);
        $this->assertStringNotContainsString(get_string('nopresets', 'mod_fancytimer'), $html);
    }
}
