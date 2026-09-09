async function loadProfile() {

    const response = await fetch("api/profile.php?action=get");

    const result = await response.json();

    if (!result.success) {

       showFlashMessage(
            result.message,
            "error"
        );

        return;

    }

    const user = result.data;

    document.getElementById("profileName").value = user.name;

    document.getElementById("profileEmail").value = user.email;

    document.getElementById("sidebarName").innerText = user.name;

    document.getElementById("sidebarRole").innerText = user.role;

    document.getElementById("sidebarEmail").innerText = user.email;

    document.getElementById("sidebarStatus").innerText = user.status;

    document.getElementById("sidebarLastLogin").innerText = user.last_login;

    document.getElementById("profileId").value = user.user_id;

    document.getElementById("sidebarId").innerText = user.user_id;

    updateAvatar("profileAvatar", user.profile_picture, "");
    updateAvatar("sidebarAvatar", user.profile_picture, "");
    updateAvatar("topProfileAvatar", user.profile_picture, "");

}

loadProfile();



function updateAvatar(elementId, imagePath, fallbackText) {

    const avatar = document.getElementById(elementId);

    if (!avatar) {

        return;

    }

    avatar.innerHTML = "";

    if (imagePath) {

        const image = document.createElement("img");
        image.src = imagePath + "?v=" + Date.now();
        image.alt = "Profile avatar";
        avatar.appendChild(image);

    } else {

        avatar.innerText = fallbackText;

    }

}



async function uploadAvatar(){

    const avatarInput = document.getElementById("avatarInput");

    if (!avatarInput.files.length) {

        return;

    }

    const formData = new FormData();
    formData.append("action", "uploadAvatar");
    formData.append("avatar", avatarInput.files[0]);

    const response = await fetch("api/profile.php",
        {
            method:"POST",
            body:formData
        }
    );

    const result = await response.json();

    showFlashMessage(
        result.message,
        result.success ? "success" : "error"
    );

    if(result.success){

        await loadProfile();

    }

    avatarInput.value = "";

}



async function updateProfile(){

    const formData = new FormData();
    formData.append("action","update");

    formData.append(
        "name",
        document.getElementById("profileName").value
    );

    const response = await fetch("api/profile.php",
        {
            method:"POST",
            body:formData
        }
    );

    const result = await response.json();

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

    if(result.success){

        loadProfile();

        document.querySelector(".profile-card-info h4").textContent =
            document.getElementById("profileName").value;

        document.getElementById("topProfileName").textContent =
            document.getElementById("profileName").value;

    }

}

async function changePassword(){

    const formData = new FormData();

    formData.append("action","changePassword");

    formData.append(
        "currentPassword",
        document.getElementById("currentPassword").value
    );

    formData.append(
        "newPassword",
        document.getElementById("newPassword").value
    );

    formData.append(
        "confirmPassword",
        document.getElementById("confirmPassword").value
    );

    const response = await fetch("api/profile.php",
        {
            method:"POST",
            body:formData
        }
    );

    const result = await response.json();

        showFlashMessage(
        result.message,
        result.success ? "success" : "error"
        );

    if(result.success){

        document.getElementById("currentPassword").value="";
        document.getElementById("newPassword").value="";
        document.getElementById("confirmPassword").value="";

    }

}
