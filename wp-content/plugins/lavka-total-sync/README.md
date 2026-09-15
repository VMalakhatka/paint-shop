# lavka-total-sync

## Contextual image help (2026-09-15)

`inc/operator-help.php` renders a responsive four-step operator guide with native
keyboard-accessible details and links to the relevant image workflow screens.
English source messages have Ukrainian and Russian PO/MO translations. The human
source of truth is [the media manager guide](../../../docs/MEDIA_MANAGER_GUIDE_UK.md).
Help is inside the existing capability-checked admin page; it does not add endpoints
or trigger uploads or synchronization. Deploy the changed templates and language
catalogs together; no activation or database migration is required for an active
plugin. Rollback restores those files together. Live admin rendering must be checked
after deployment.
