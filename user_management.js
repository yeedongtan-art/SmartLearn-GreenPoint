async function loadUsers(){

    const keyword =

    document.getElementById("searchInput").value.trim();

    const role =

    document.getElementById("roleFilter").value;

    const response = await fetch(

        "api/user_management.php?action=getUsers"

        + "&keyword=" + encodeURIComponent(keyword)

        + "&role=" + encodeURIComponent(role)

    );

    const result = await response.json();

    if(!result.success){

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

        return;

    }

    const tbody =
    document.getElementById("tableBody");

    tbody.innerHTML = "";

    result.data.forEach(user=>{

        tbody.innerHTML += `

        <tr>

            <td>${user.user_id}</td>

            <td>${user.name}</td>

            <td>${user.email}</td>

            <td>${user.role}</td>

            <td>

                ${user.class_name
                    ? `Form ${user.year} ${user.class_name}`
                    : "-"}

            </td>

            <td>

                <span class="badge ${user.status=="Active"
                    ? "active-status"
                    : "inactive-status"}">

                    ${user.status}

                </span>

            </td>

            <td>

                <div class="actions">

                    <button
                    class="action-btn"
                    onclick="editUser(${user.user_id})">

                        <i class="fa-solid fa-pen"></i>

                    </button>

                    <button
                    class="action-btn"
                    onclick="resetPassword(${user.user_id})">

                        <i class="fa-solid fa-key"></i>

                    </button>

                    <button
                    class="action-btn"
                    onclick="deleteUser(${user.user_id})">

                        <i class="fa-solid fa-trash"></i>

                    </button>

                </div>

            </td>

        </tr>

        `;

    });

    document.getElementById("countInfo").innerText =
    `Showing ${result.data.length} result(s)`;

}

async function createUser(){

    const email =
    document.getElementById("newEmail").value.trim();

    const emailPattern =
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if(!emailPattern.test(email)){

        showFlashMessage("Please enter a valid email address.","error");

        return;

    }

    const formData = new FormData();

    formData.append(
        "action",
        "add"
    );

    formData.append(
        "name",
        document.getElementById("newName").value
    );

    formData.append(
        "email",
        document.getElementById("newEmail").value.trim().toLowerCase()
    );

    formData.append(
        "password",
        document.getElementById("newPassword").value
    );

    formData.append(
        "role",
        document.getElementById("newRole").value
    );

    formData.append(
        "class_id",
        document.getElementById("newClass").value
    );

    const response = await fetch(

        "api/user_management.php",

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

        closeModal("addUserModal");

        loadUsers();

        document.getElementById("newName").value="";
        document.getElementById("newEmail").value="";
        document.getElementById("newPassword").value=randomPassword();
        document.getElementById("newRole").value="Student";
        document.getElementById("newClass").selectedIndex=0;

        toggleClassField();

    }

}

async function loadClasses(){

    const response = await fetch(

        "api/user_management.php?action=getClasses"

    );

    const result = await response.json();

    const select = document.getElementById("newClass");

    select.innerHTML="";

    result.data.forEach(classroom=>{

        select.innerHTML += `

            <option value="${classroom.class_id}">

                ${classroom.class_name}

            </option>

        `;

    });

}

let currentUser = null;
let resetUserId = null;

async function editUser(id){

    const sessionUserId = document.body.dataset.userid;

    if(id == sessionUserId){

        showFlashMessage(
            "You cannot edit your own account.",
            "error"
        );

        return;

    }

    currentUser = id;

    const response = await fetch(

        "api/user_management.php?action=getUser&user_id="+id

    );



    const result = await response.json();

    const user = result.data;

    document.getElementById("editId").value =
    user.user_id;

    document.getElementById("editName").value =
    user.name;

    document.getElementById("editEmail").value =
    user.email;

    document.getElementById("editRole").value =
    user.role;

    document.getElementById("editStatus").value =
    user.status;

    await loadEditClasses(user.class_id);

    toggleEditClassField();

    openModal("editUserModal");

}

async function loadEditClasses(selectedId=""){

    const response = await fetch(

        "api/user_management.php?action=getClasses"

    );

    const result = await response.json();

    const select =
    document.getElementById("editClass");

    select.innerHTML = "";

    result.data.forEach(classroom=>{

        select.innerHTML += `

            <option
                value="${classroom.class_id}"
                ${classroom.class_id==selectedId?"selected":""}>

                ${classroom.class_name}

            </option>

        `;

    });

}

async function saveEditedUser(){

const email =
document.getElementById("editEmail").value.trim();

const emailPattern =
/^[^\s@]+@[^\s@]+\.[^\s@]+$/;

if(!emailPattern.test(email)){

    showFlashMessage("Please enter a valid email address.","error");

    return;

}

    const formData = new FormData();

    formData.append("action","update");

    formData.append(
        "user_id",
        currentUser
    );

    formData.append(
        "name",
        document.getElementById("editName").value
    );

    formData.append(
        "email",
        document.getElementById("editEmail").value.trim().toLowerCase()
    );

    formData.append(
        "role",
        document.getElementById("editRole").value
    );

    formData.append(
        "status",
        document.getElementById("editStatus").value
    );

    formData.append(
        "class_id",
        document.getElementById("editClass").value
    );

    const response = await fetch(

        "api/user_management.php",

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

        closeModal("editUserModal");

        loadUsers();

    }

}

async function deleteUser(id){

    const sessionUserId = document.body.dataset.userid;

    if(id == sessionUserId){

        showFlashMessage("You cannot delete your own account.","error");

        return;

    }

    if(!confirm(
        "Are you sure you want to delete this user?"
    )){

        return;

    }

    const formData = new FormData();

    formData.append(
        "action",
        "delete"
    );

    formData.append(
        "user_id",
        id
    );

    const response = await fetch(

        "api/user_management.php",

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

        loadUsers();

    }

}

async function resetPassword(id){

    const sessionUserId = document.body.dataset.userid;

    if(id == sessionUserId){

        showFlashMessage("You cannot reset your own password. Please use the Profile page.","error");

        return;

    }

    resetUserId = id;

    const response = await fetch(

        "api/user_management.php?action=getUser&user_id="+id

    );

    const result = await response.json();

    document.getElementById("resetUser").value =

    result.data.name;

    document.getElementById("resetPassword").value = "";

    openModal("resetModal");

}

async function confirmReset(){

    if(document.getElementById("resetPassword").value==""){

    showFlashMessage("Please generate a temporary password first.","error");

    return;

}

    const formData = new FormData();

    formData.append(

    "password",

    document.getElementById("resetPassword").value

);

    formData.append(

        "action",

        "resetPassword"

    );

    formData.append(

        "user_id",

        resetUserId

    );

    const response = await fetch(

        "api/user_management.php",

        {

            method:"POST",

            body:formData

        }

    );

    const result = await response.json();

    if(result.success){

        showFlashMessage(

            result.message +

            "\n\nTemporary Password: " +

            document.getElementById("resetPassword").value

        );

        closeModal("resetModal");

    }

}

function randomPassword(){

    const chars =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@#$%&";

    let password="";

    for(let i=0;i<8;i++){

        password+=chars.charAt(

            Math.floor(Math.random()*chars.length)

        );

    }

    return password;

}

function generatePassword(){

    document.getElementById("resetPassword").value =
    randomPassword();

}


function addUser(){

    document.getElementById("newName").value = "";

    document.getElementById("newEmail").value = "";

    document.getElementById("newRole").value = "Student";

    document.getElementById("newPassword").value = randomPassword();

    document.getElementById("newClass").selectedIndex = 0;

    toggleClassField();

    openModal("addUserModal");

}

function toggleClassField(){

    const role =
    document.getElementById("newRole").value;

    const classGroup =
    document.getElementById("classGroup");

    classGroup.style.display =
    role=="Student"
    ? "block"
    : "none";

}

function toggleEditClassField(){

    const role =
    document.getElementById("editRole").value;

    const classGroup =
    document.getElementById("editClassGroup");

    classGroup.style.display =
    role=="Student"
    ? "block"
    : "none";

}

function applyFilter(){

    loadUsers();

}

loadUsers();

loadClasses();