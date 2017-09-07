function hide_error(event) {
    var errordiv = event.currentTarget
    errordiv.style.display = "none"
}

document.addEventListener('DOMContentLoaded', function messagebox() {
    var result = document.querySelector('.result')
    setTimeout(function fadeout() {
	result.style.opacity = 0;
    }, 5000)
    setTimeout(function fadeout() {
	result.style.display = 'none';
    }, 6000)
})
