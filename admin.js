//Modal
function openModal(id){

    document
    .getElementById(id)
    .classList.add("show");

}

function closeModal(id){

    document
    .getElementById(id)
    .classList.remove("show");

}

//LOGOUT POPUP

async function confirmLogout(){

    const formData = new FormData();

    formData.append("action","logout");

    const response = await fetch(
        "api/auth.php",
        {
            method:"POST",
            body:formData
        }
    );

    const result = await response.json();

    if(result.success){

        window.location.href="login.php";

    }else{

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

    }

}

//FLASH MESSAGE

function showFlashMessage(message,type="success"){

    let flash=document.getElementById("flashMessage");

    if(!flash){

        flash=document.createElement("div");

        flash.id="flashMessage";

        flash.className="flash-message";

        document.body.appendChild(flash);

    }

    flash.className="flash-message flash-"+type;

    flash.textContent=message;

    requestAnimationFrame(()=>{

        flash.classList.add("show");

    });

    clearTimeout(flash.timer);

    flash.timer=setTimeout(()=>{

        flash.classList.remove("show");

    },2500);

}

// UI THEME AND PROFILE DROPDOWN

document.addEventListener("DOMContentLoaded", ()=>{

    const themeToggle = document.getElementById("themeToggle");
    const profileToggle = document.getElementById("profileDropdownToggle");
    const profileMenu = document.getElementById("profileDropdownMenu");

    if(!themeToggle && !profileToggle){

        return;

    }

    const savedTheme = localStorage.getItem("smartlearn-theme");
    const preferredTheme = window.matchMedia("(prefers-color-scheme: dark)").matches
        ? "dark"
        : "light";

    document.documentElement.setAttribute(
        "data-theme",
        savedTheme || preferredTheme
    );

    if(themeToggle){

        themeToggle.addEventListener("click", ()=>{

            const currentTheme = document.documentElement.getAttribute("data-theme");
            const nextTheme = currentTheme === "dark" ? "light" : "dark";

            document.documentElement.setAttribute("data-theme", nextTheme);
            localStorage.setItem("smartlearn-theme", nextTheme);
            window.dispatchEvent(new Event("smartlearn-theme-change"));

        });

    }

    if(!profileToggle || !profileMenu){

        return;

    }

    profileToggle.addEventListener("click", (event)=>{

        event.stopPropagation();

        const isOpen = profileMenu.classList.toggle("show");
        profileToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");

    });

    document.addEventListener("click", (event)=>{

        if(!profileMenu.contains(event.target) && !profileToggle.contains(event.target)){

            profileMenu.classList.remove("show");
            profileToggle.setAttribute("aria-expanded", "false");

        }

    });

    document.addEventListener("keydown", (event)=>{

        if(event.key === "Escape"){

            profileMenu.classList.remove("show");
            profileToggle.setAttribute("aria-expanded", "false");

        }

    });

});

