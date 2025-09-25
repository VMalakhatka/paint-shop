
let mcloudHeartbeatTimeout = null;

function mcloudHeartbeat() {
    let currentPulseTime = parseInt(window.localStorage.getItem('mcloudpulseTime'));

    if ((currentPulseTime > 0) && (Date.now() - currentPulseTime < <?php echo e($heartbeatFrequency); ?>)) {

        mcloudHeartbeatTimeout = setTimeout(mcloudHeartbeat, <?php echo e($heartbeatFrequency * 2); ?>);
    } else {

        window.localStorage.setItem('mcloudpulseUrl', window.location.href);
        window.localStorage.setItem('mcloudpulseTime', Date.now());
        jQuery.post(ajaxurl, { 'action': 'mcloud_task_heartbeat'});
        mcloudHeartbeatTimeout = setTimeout(mcloudHeartbeat, <?php echo e($heartbeatFrequency); ?>);
    }
}

document.addEventListener('DOMContentLoaded', function(){
    window.addEventListener('storage', e => {
        if ((e.storageArea !== window.localStorage) || (e.key !== 'mcloudpulseTime')) {
            return;
        }

        let url = window.localStorage.getItem('mcloudpulseUrl');
        let time = window.localStorage.getItem('mcloudpulseTime');

        if (url === window.location.href) {

            return;
        }


        clearTimeout(mcloudHeartbeatTimeout);
        mcloudHeartbeatTimeout = setTimeout(mcloudHeartbeat, <?php echo e($heartbeatFrequency * 2); ?>);
    });


    mcloudHeartbeat();
});
<?php /**PATH /Users/admin/Local Sites/paint/app/public/wp-content/plugins/ilab-media-tools/views/base/heartbeat.blade.php ENDPATH**/ ?>