# Fancy Timer (mod_fancytimer)

A Moodle activity for a classroom timer you project. The timer is a sequence of segments, for
example three speakers with two minutes each, and an optional rotation break at the end
("Change groups!"). It can stop after one cycle or repeat.

## Features

- **Segments** each with a length, a label shown above the countdown, a background tint, a
  countdown text colour, an optional headline picture and a changeover sound.
- **Rotation break**, an optional final segment with its own label, colour and sound.
- **Sounds**: a built-in library from gentle (soft chime, bell, gong) to alarming (buzzer, alarm,
  klaxon), made in the browser so nothing has to be downloaded. Teachers can also record their
  own messages in the browser or upload sound files. A starting sound can play when the timer
  begins. Every sound in the settings has a Play button. An
  optional warning sounds in the last seconds of each segment (ticking every second, or one chime); it is off by default.
- **Display**: a huge countdown, full-screen mode for projectors, a progress bar for the
  whole cycle that can be clicked to jump to any point, an optional progress ring for the current segment, controls that can be hidden, and the running order of segments.
- **Controls**: start, pause/resume, back, skip, reset and +30 s, with keyboard shortcuts:
  Space start/pause, → skip, ← back, R reset, F full screen, H hide or show the controls, + add 30 s, ? help.
- **Phone remote**: scan the QR code on the teacher's screen to control the timer from a phone.
  Students who open the activity see a silent mirror of the teacher's timer.
- **Presets and templates**: bundled templates (Three-person talk, Pair talk, Think-pair-share,
  Simple countdown). You can save your own timers as presets and use them in any course, and
  export and import presets as JSON files.
- **Accessibility**: screen-reader announcements at each changeover, a contrast check with a
  warning in the settings, a visual flash at each changeover (a static outline when
  reduced motion is requested), full keyboard operation, and labels that never rely on colour.

## Requirements

Moodle 5.2 or 5.3. Recording in the browser needs HTTPS (browsers allow microphone access only
on secure pages).

## Capabilities

| Capability | Default roles | Allows |
|---|---|---|
| `mod/fancytimer:addinstance` | editing teacher, manager | adding the activity |
| `mod/fancytimer:view` | guest, student, teacher, editing teacher, manager | viewing the timer |
| `mod/fancytimer:control` | teacher, editing teacher, manager | running it, phone remote |

Editing segments, recordings and presets needs `moodle/course:manageactivities`.

## Privacy

Teacher recordings, saved presets and remote-control commands are stored with the user who made
them and are covered by the Privacy API (export and deletion).

## Licence

GNU GPL v3 or later. Copyright 2026 Adam Jenkins <adam@wisecat.net>.
