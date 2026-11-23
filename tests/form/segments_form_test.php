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

namespace mod_fancytimer\form;

use mod_fancytimer\local\recordings;
use mod_fancytimer\local\segments;
use moodle_url;

/**
 * Tests for the segment editor form, built and submitted as edit.php does.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(segments_form::class)]
final class segments_form_test extends \advanced_testcase {
    /** @var \stdClass Instance record. */
    private \stdClass $fancytimer;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var \stdClass[] The three existing segments. */
    private array $segments;

    /**
     * A threeperson timer, edited by the admin.
     */
    protected function setUp(): void {
        global $CFG, $DB, $PAGE;
        require_once($CFG->libdir . '/filelib.php');
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $this->fancytimer = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_id('fancytimer', $timer->cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->segments = segments::get_segments((int)$this->fancytimer->id);
        $PAGE->set_url('/mod/fancytimer/edit.php', ['id' => $this->cm->id]);
        $PAGE->set_context($this->context);
    }

    /**
     * Restore the superglobals mock_submit() replaced.
     */
    protected function tearDown(): void {
        $_POST = [];
        $_FILES = [];
        parent::tearDown();
    }

    /**
     * Build the form with edit.php's custom data.
     *
     * @return segments_form
     */
    private function make_form(): segments_form {
        return new segments_form(new moodle_url('/mod/fancytimer/edit.php', ['id' => $this->cm->id]), [
            'fancytimer' => $this->fancytimer,
            'cm' => $this->cm,
            'context' => $this->context,
            'recordings' => recordings::for_activity((int)$this->fancytimer->id),
            'maxbytes' => 0,
        ]);
    }

    /**
     * A valid submission of the three existing segments plus the rotation break.
     *
     * @return array
     */
    private function valid_post(): array {
        $post = [
            'id' => $this->cm->id,
            segments_form::REPEAT_NAME => 3,
            'rotationenabled' => 1,
            'rotationlabel' => 'Change groups!',
            'rotationduration' => ['minutes' => 0, 'seconds' => 30],
            'rotationbgcolour' => '#d1e7dd',
            'rotationtextcolour' => '#212529',
            'rotationimage' => file_get_unused_draft_itemid(),
            'rotationsound' => 'bell',
            'submitbutton' => get_string('savesegments', 'mod_fancytimer'),
        ];
        foreach ($this->segments as $i => $segment) {
            $post['segmentid'][$i] = (int)$segment->id;
            $post['label'][$i] = 'Person ' . ($i + 1) . ' is speaking';
            $post['duration'][$i] = ['minutes' => 2, 'seconds' => 0];
            $post['bgcolour'][$i] = '#cfe2ff';
            $post['textcolour'][$i] = '#212529';
            $post['image'][$i] = file_get_unused_draft_itemid();
            $post['sound'][$i] = 'chime';
        }
        return $post;
    }

    /**
     * Submit and return [form data or null, the QuickForm object holding the errors].
     *
     * @param array $post
     * @return array
     */
    private function submit(array $post): array {
        segments_form::mock_submit($post);
        $form = $this->make_form();
        $data = $form->get_data();
        $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        return [$data, $mform];
    }

    /**
     * A valid submission comes back with the values edit.php reads.
     */
    public function test_valid_submission(): void {
        $post = $this->valid_post();
        $post['label'][1] = 'Ana is speaking';
        $post['duration'][2] = ['minutes' => 1, 'seconds' => 45];
        [$data] = $this->submit($post);

        $this->assertNotNull($data);
        $this->assertSame('Ana is speaking', $data->label[1]);
        $this->assertSame(120, segments_form::duration_from($data->duration[0]));
        $this->assertSame(105, segments_form::duration_from($data->duration[2]));
        $this->assertEquals($this->segments[1]->id, $data->segmentid[1]);
        $this->assertSame('chime', $data->sound[0]);
        $this->assertEquals(1, $data->rotationenabled);
        $this->assertSame(30, segments_form::duration_from($data->rotationduration));
    }

    /**
     * A segment without a label is refused with the label error on that segment.
     */
    public function test_missing_label(): void {
        $post = $this->valid_post();
        $post['label'][1] = '   ';
        [$data, $mform] = $this->submit($post);

        $this->assertNull($data);
        $this->assertSame(get_string('err_labelrequired', 'mod_fancytimer'), $mform->getElementError('label[1]'));
        $this->assertEmpty($mform->getElementError('label[0]'));
    }

    /**
     * A zero-length segment is refused with the duration error.
     */
    public function test_zero_duration(): void {
        $post = $this->valid_post();
        $post['duration'][2] = ['minutes' => 0, 'seconds' => 0];
        [$data, $mform] = $this->submit($post);

        $this->assertNull($data);
        $this->assertSame(
            get_string('err_duration', 'mod_fancytimer', format_time(segments::MAX_DURATION)),
            $mform->getElementError('duration[2]')
        );
        $this->assertEmpty($mform->getElementError('duration[0]'));
    }

    /**
     * Colours that are not #rgb / #rrggbb are refused, for segments and for the rotation break.
     */
    public function test_bad_colour(): void {
        $post = $this->valid_post();
        $post['bgcolour'][0] = 'blue';
        $post['textcolour'][2] = '#12345g';
        $post['rotationbgcolour'] = 'zzz';
        [$data, $mform] = $this->submit($post);

        $error = get_string('err_colour', 'mod_fancytimer');
        $this->assertNull($data);
        $this->assertSame($error, $mform->getElementError('bgcolour[0]'));
        $this->assertSame($error, $mform->getElementError('textcolour[2]'));
        $this->assertSame($error, $mform->getElementError('rotationbgcolour'));
        $this->assertEmpty($mform->getElementError('bgcolour[1]'));
    }

    /**
     * Short colours are accepted (they are normalised when saved).
     */
    public function test_short_colour_accepted(): void {
        $post = $this->valid_post();
        $post['bgcolour'][0] = '#ABC';
        $post['textcolour'][0] = '000';
        [$data] = $this->submit($post);
        $this->assertNotNull($data);
    }

    /**
     * An empty rotation label is refused only while the rotation break is on.
     */
    public function test_rotation_label_only_checked_when_enabled(): void {
        $post = $this->valid_post();
        $post['rotationlabel'] = '';
        [$data, $mform] = $this->submit($post);
        $this->assertNull($data);
        $this->assertSame(get_string('err_labelrequired', 'mod_fancytimer'), $mform->getElementError('rotationlabel'));

        $post['rotationenabled'] = 0;
        [$data] = $this->submit($post);
        $this->assertNotNull($data);
    }

    /**
     * A deleted segment is skipped by validation and missing from the data.
     */
    public function test_deleted_segment_is_not_validated(): void {
        $post = $this->valid_post();
        $post['label'][1] = '';
        $post[segments_form::DELETE_NAME . '-hidden'] = [1 => 1];
        [$data] = $this->submit($post);

        $this->assertNotNull($data);
        $this->assertArrayNotHasKey(1, $data->label);
        $this->assertSame([0, 2], array_keys($data->label));
    }
}
