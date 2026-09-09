async function handleLogin() {

    const email = document.querySelector('input[type="email"]').value.trim();

    const password = document.querySelector('input[type="password"]').value.trim();

    if (email === "" || password === "") {
        showFlashMessage("Please enter email and password.","error");
        return;
    }

    const formData = new FormData();

    formData.append("action","login");

    formData.append("email", email);

    formData.append("password", password);

    const response = await fetch("api/auth.php", {
        method: "POST",
        body: formData
    });

    const result = await response.json();

    showFlashMessage(
        result.message,
        result.success ? "success" : "error"
    );

    if (result.success) {
        window.location.href = result.redirect || "admin_dashboard.php";
    }

}

function showForgotPasswordMessage(){

document
.getElementById("forgotPasswordModal")
.style.display="flex";

}

function closeForgotPasswordMessage(){

document
.getElementById("forgotPasswordModal")
.style.display="none";

}