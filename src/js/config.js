
function webapp() {
    const domain_approv = [
        "localhost",
        "192.168.1.10",
        "music.partitions.ovh"
    ];
    if (domain_approv.includes(window.location.host)) {
        // application web
        return true;

    } else {
        // Application smartphone
        return false;
    }
}

function get_url() {
    if(webapp()) {
        return "";
    }
    else {
        return "https://music.partitions.ovh/php/tools/";
    }
}

function get_url_from_base() {
    if(webapp()) {
        return "";
    }
    else {
        return "https://music.partitions.ovh/";
    }
}